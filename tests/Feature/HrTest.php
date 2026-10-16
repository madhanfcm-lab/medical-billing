<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DashboardService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

class HrTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private Employee $asha;

    private Employee $ravi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->asha = Employee::factory()->create(['name' => 'Asha Nair', 'employee_code' => 'EMP-001']);
        $this->ravi = Employee::factory()->create(['name' => 'Ravi Kumar', 'employee_code' => 'EMP-002']);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function sheet(string $date, array $rows): array
    {
        return ['date' => $date, 'attendance' => $rows];
    }

    private function mark(string $date, array $rows, string $role = 'receptionist'): TestResponse
    {
        return $this->as($role)->post('/attendance', $this->sheet($date, $rows));
    }

    // --- employees -----------------------------------------------------------------

    public function test_admin_manages_employees(): void
    {
        $this->as('admin')->post('/employees', [
            'employee_code' => ' emp-010 ', 'name' => 'Meena Joshi', 'designation' => 'Receptionist', 'phone' => '9811122233',
            'joining_date' => today()->subMonth()->toDateString(), 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $e = Employee::where('name', 'Meena Joshi')->firstOrFail();
        $this->assertSame('EMP-010', $e->employee_code);                 // trimmed + upper-cased
        $this->assertNotNull($e->created_by);

        $this->as('admin')->put("/employees/{$e->id}", [
            'employee_code' => 'EMP-010', 'name' => 'Meena J', 'joining_date' => $e->joining_date->toDateString(), 'is_active' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($e->fresh()->is_active);
    }

    public function test_employee_validation(): void
    {
        $r = $this->as('admin');
        $base = ['employee_code' => 'EMP-050', 'name' => 'X', 'joining_date' => today()->toDateString()];

        $r->post('/employees', array_merge($base, ['employee_code' => 'EMP-001']))->assertSessionHasErrors('employee_code');   // taken
        $r->post('/employees', array_merge($base, ['employee_code' => 'bad code!']))->assertSessionHasErrors('employee_code');
        $r->post('/employees', array_merge($base, ['employee_code' => '']))->assertSessionHasErrors('employee_code');
        $r->post('/employees', array_merge($base, ['name' => '']))->assertSessionHasErrors('name');
        $r->post('/employees', array_merge($base, ['phone' => '12345']))->assertSessionHasErrors('phone');
        $r->post('/employees', array_merge($base, ['joining_date' => today()->addDay()->toDateString()]))->assertSessionHasErrors('joining_date');
        $r->post('/employees', array_merge($base, ['joining_date' => '']))->assertSessionHasErrors('joining_date');
        $r->post('/employees', $base)->assertSessionHasNoErrors();   // phone and designation are optional
    }

    public function test_employee_code_can_be_kept_on_edit_and_suggested_on_create(): void
    {
        $this->as('admin')->put("/employees/{$this->asha->id}", [
            'employee_code' => 'EMP-001', 'name' => 'Asha N', 'joining_date' => $this->asha->joining_date->toDateString(), 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^EMP-\d{3}$/', Employee::suggestCode());
        $this->assertFalse(Employee::where('employee_code', Employee::suggestCode())->exists());
        $this->as('admin')->get('/employees/create')->assertOk()->assertSee(Employee::suggestCode());
    }

    public function test_deleting_an_employee_keeps_history(): void
    {
        Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => today()->toDateString(), 'status' => 'present']);
        $this->as('admin')->delete("/employees/{$this->asha->id}")->assertRedirect(route('employees.index'));

        $this->assertSoftDeleted($this->asha);
        $this->assertSame(1, Attendance::count());
        $this->assertSame('Asha Nair', Attendance::first()->employee->name);   // still resolvable
    }

    public function test_only_admin_reaches_employee_and_salary_screens(): void
    {
        foreach (['/employees', '/employees/create', '/salary-payments', '/salary-payments/create', "/employees/{$this->asha->id}"] as $url) {
            $this->as('receptionist')->get($url)->assertForbidden();
            $this->as('doctor')->get($url)->assertForbidden();
            $this->as('admin')->get($url)->assertOk();
        }
    }

    // --- daily attendance ------------------------------------------------------------

    public function test_save_the_whole_sheet_in_one_go(): void
    {
        $today = today()->toDateString();
        $this->mark($today, [
            $this->asha->id => ['status' => 'present', 'remark' => ''],
            $this->ravi->id => ['status' => 'half_day', 'remark' => 'Doctor appointment'],
        ])->assertSessionHasNoErrors();

        $a = Attendance::where('employee_id', $this->ravi->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::HalfDay, $a->status);
        $this->assertSame('Doctor appointment', $a->remark);
        $this->assertNull(Attendance::where('employee_id', $this->asha->id)->first()->remark);
        $this->assertNotNull($a->created_by);
        $this->assertSame(2, Attendance::count());
    }

    public function test_saving_again_updates_instead_of_duplicating(): void
    {
        $today = today()->toDateString();
        $this->mark($today, [$this->asha->id => ['status' => 'present']]);
        $this->mark($today, [$this->asha->id => ['status' => 'absent', 'remark' => 'No call']]);

        $this->assertSame(1, Attendance::count());
        $this->assertSame(AttendanceStatus::Absent, Attendance::first()->status);
    }

    public function test_the_database_itself_refuses_a_second_record_for_the_same_day(): void
    {
        $row = ['employee_id' => $this->asha->id, 'attendance_date' => today()->toDateString(), 'status' => 'present'];
        Attendance::create($row);

        $this->expectException(QueryException::class);
        Attendance::create($row);
    }

    public function test_same_employee_on_different_days_is_fine(): void
    {
        $this->mark(today()->toDateString(), [$this->asha->id => ['status' => 'present']]);
        $this->mark(today()->subDay()->toDateString(), [$this->asha->id => ['status' => 'absent']]);

        $this->assertSame(2, Attendance::where('employee_id', $this->asha->id)->count());
    }

    public function test_unmarked_rows_are_skipped_and_unchanged_rows_are_not_touched(): void
    {
        $today = today()->toDateString();
        $svc = app(AttendanceService::class);

        $r = $svc->save(today(), [$this->asha->id => ['status' => 'present'], $this->ravi->id => ['status' => '', 'remark' => 'x']]);
        $this->assertSame(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1], $r);
        $this->assertSame(1, Attendance::count());

        $r = $svc->save(today(), [$this->asha->id => ['status' => 'present']]);
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'skipped' => 0], $r);

        $r = $svc->save(today(), [$this->asha->id => ['status' => 'present', 'remark' => 'Came early']]);
        $this->assertSame(1, $r['updated']);
        $this->assertSame('Came early', Attendance::first()->remark);
    }

    public function test_future_dates_are_rejected(): void
    {
        foreach (['admin', 'receptionist'] as $role) {
            $this->mark(today()->addDay()->toDateString(), [$this->asha->id => ['status' => 'present']], $role)->assertSessionHasErrors('date');
        }
        $this->assertSame(0, Attendance::count());
    }

    public function test_staff_can_edit_three_days_back_but_not_further(): void
    {
        $this->mark(today()->subDays(3)->toDateString(), [$this->asha->id => ['status' => 'present']])->assertSessionHasNoErrors();
        $this->mark(today()->subDays(4)->toDateString(), [$this->asha->id => ['status' => 'present']])->assertSessionHasErrors('date');

        $this->assertSame(1, Attendance::count());
    }

    public function test_admin_can_edit_any_past_date(): void
    {
        $old = today()->subDays(40)->toDateString();
        $this->mark($old, [$this->asha->id => ['status' => 'leave']], 'admin')->assertSessionHasNoErrors();

        $this->assertSame(AttendanceStatus::Leave, Attendance::first()->status);
    }

    public function test_doctor_cannot_mark_attendance_but_can_not_even_see_it(): void
    {
        $this->mark(today()->toDateString(), [$this->asha->id => ['status' => 'present']], 'doctor')->assertForbidden();
        $this->as('doctor')->get('/attendance')->assertForbidden();
    }

    public function test_invalid_status_and_unknown_employee_are_rejected(): void
    {
        $today = today()->toDateString();

        $this->mark($today, [$this->asha->id => ['status' => 'sleeping']])->assertSessionHasErrors('attendance.'.$this->asha->id.'.status');
        $this->mark($today, [9999 => ['status' => 'present']])->assertSessionHasErrors('attendance');
        $this->mark($today, [])->assertSessionHasErrors('attendance');
        $this->mark('2026-02-30', [$this->asha->id => ['status' => 'present']])->assertSessionHasErrors('date');
        $this->assertSame(0, Attendance::count());
    }

    public function test_inactive_and_not_yet_joined_employees_are_not_on_the_sheet(): void
    {
        $inactive = Employee::factory()->create(['is_active' => false, 'name' => 'Gone Person']);
        $newbie = Employee::factory()->create(['joining_date' => today()->toDateString(), 'name' => 'New Joiner']);
        $yesterday = today()->subDay();

        $ids = app(AttendanceService::class)->sheet($yesterday)->pluck('id');
        $this->assertTrue($ids->contains($this->asha->id));
        $this->assertFalse($ids->contains($inactive->id));
        $this->assertFalse($ids->contains($newbie->id));            // joined after that date

        $this->mark($yesterday->toDateString(), [$inactive->id => ['status' => 'present']])->assertSessionHasErrors('attendance');
        $this->mark($yesterday->toDateString(), [$newbie->id => ['status' => 'present']])->assertSessionHasErrors('attendance');
        $this->mark(today()->toDateString(), [$newbie->id => ['status' => 'present']])->assertSessionHasNoErrors();   // fine from the joining date
    }

    public function test_someone_deactivated_later_stays_visible_on_days_they_have_a_record(): void
    {
        $date = today()->subDays(2);
        Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => $date->toDateString(), 'status' => 'present']);
        $this->asha->update(['is_active' => false]);

        $this->assertTrue(app(AttendanceService::class)->sheet($date)->contains('id', $this->asha->id));
        $this->assertFalse(app(AttendanceService::class)->sheet(today())->contains('id', $this->asha->id));
        $this->mark($date->toDateString(), [$this->asha->id => ['status' => 'absent']])->assertSessionHasNoErrors();   // can still be corrected
    }

    public function test_sheet_screen(): void
    {
        Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => today()->toDateString(), 'status' => 'absent', 'remark' => 'Fever']);
        $r = $this->as('receptionist');

        $r->get('/attendance')->assertOk()->assertSee('Asha Nair')->assertSee('Ravi Kumar')->assertSee('Mark all present')->assertSee('Fever')
            ->assertSee('Save attendance');
        $r->get('/attendance?date='.today()->subDay()->toDateString())->assertOk()->assertSee('Save attendance');
        $r->get('/attendance?date='.today()->subDays(10)->toDateString())->assertOk()->assertSee('read-only for you')->assertDontSee('Save attendance');
        $r->get('/attendance?date='.today()->addDays(5)->toDateString())->assertOk()->assertSee(today()->format('l, d-m-Y'));   // future → today
        $r->get('/attendance?date=garbage')->assertOk();
        $this->as('admin')->get('/attendance?date='.today()->subDays(10)->toDateString())->assertOk()->assertSee('Save attendance');
    }

    // --- monthly grid ----------------------------------------------------------------

    public function test_monthly_grid_counts(): void
    {
        $month = today()->startOfMonth();
        foreach ([[1, 'present'], [2, 'present'], [3, 'absent'], [4, 'half_day'], [5, 'leave'], [6, 'present']] as [$day, $status]) {
            Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => $month->copy()->day($day)->toDateString(), 'status' => $status]);
        }
        Attendance::create(['employee_id' => $this->ravi->id, 'attendance_date' => $month->copy()->day(1)->toDateString(), 'status' => 'absent']);
        // other months must not leak in
        Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => $month->copy()->subMonth()->day(10)->toDateString(), 'status' => 'absent']);

        $g = app(AttendanceService::class)->monthly($month);
        $rows = collect($g['rows'])->keyBy(fn ($r) => $r['employee']->id);

        $this->assertSame($month->daysInMonth, $g['days']);
        $this->assertSame(['present' => 3, 'absent' => 1, 'half_day' => 1, 'leave' => 1], $rows[$this->asha->id]['counts']);
        $this->assertSame(['present' => 0, 'absent' => 1, 'half_day' => 0, 'leave' => 0], $rows[$this->ravi->id]['counts']);
        $this->assertSame(AttendanceStatus::HalfDay, $rows[$this->asha->id]['cells'][4]);
        $this->assertSame(['present' => 3, 'absent' => 2, 'half_day' => 1, 'leave' => 1], $g['totals']);

        $this->as('receptionist')->get('/attendance/monthly?month='.$month->format('Y-m'))->assertOk()->assertSee('Asha Nair')->assertSee('Total');
        $this->as('receptionist')->get('/attendance/monthly?month=bad')->assertOk();
    }

    public function test_monthly_grid_includes_inactive_staff_who_have_records_and_skips_late_joiners(): void
    {
        $lastMonth = today()->startOfMonth()->subMonth();
        $leaver = Employee::factory()->create(['is_active' => false, 'name' => 'Leaver', 'joining_date' => $lastMonth->copy()->subYear()->toDateString()]);
        Attendance::create(['employee_id' => $leaver->id, 'attendance_date' => $lastMonth->copy()->day(5)->toDateString(), 'status' => 'present']);
        $joiner = Employee::factory()->create(['joining_date' => today()->toDateString(), 'name' => 'Joiner']);

        $names = collect(app(AttendanceService::class)->monthly($lastMonth)['rows'])->map(fn ($r) => $r['employee']->name);
        $this->assertTrue($names->contains('Leaver'));
        $this->assertFalse($names->contains('Joiner'));
        $this->assertFalse(collect(app(AttendanceService::class)->monthly(today())['rows'])->map(fn ($r) => $r['employee']->name)->contains('Leaver'));
    }

    // --- salary entries ----------------------------------------------------------------

    private function salary(array $over = []): array
    {
        return array_merge([
            'employee_id' => $this->asha->id, 'payment_date' => today()->toDateString(), 'for_month' => today()->format('Y-m'),
            'amount' => '18500', 'mode' => 'bank_transfer', 'reference_no' => 'NEFT123', 'notes' => 'October salary',
        ], $over);
    }

    public function test_record_a_salary_payment(): void
    {
        $this->as('admin')->post('/salary-payments', $this->salary())->assertSessionHasNoErrors();

        $p = SalaryPayment::firstOrFail();
        $this->assertSame('18500.00', $p->amount);
        $this->assertSame(today()->startOfMonth()->toDateString(), $p->for_month->toDateString());   // month stored as the 1st
        $this->assertSame(today()->format('M Y'), $p->month_label);
        $this->assertSame('NEFT123', $p->reference_no);
        $this->assertNotNull($p->created_by);
    }

    public function test_cash_salary_needs_no_reference_and_reference_is_dropped(): void
    {
        $this->as('admin')->post('/salary-payments', $this->salary(['mode' => 'cash', 'reference_no' => '']))->assertSessionHasNoErrors();

        $this->assertNull(SalaryPayment::first()->reference_no);
    }

    public function test_salary_validation(): void
    {
        $r = $this->as('admin');
        $bad = fn (array $over, $errors) => $r->post('/salary-payments', $this->salary($over))->assertSessionHasErrors($errors);

        $bad(['employee_id' => ''], 'employee_id');
        $bad(['employee_id' => 9999], 'employee_id');
        $bad(['amount' => ''], 'amount');
        $bad(['amount' => '0'], 'amount');
        $bad(['amount' => '-5'], 'amount');
        $bad(['amount' => '100.555'], 'amount');
        $bad(['payment_date' => today()->addDay()->toDateString()], 'payment_date');
        $bad(['payment_date' => ''], 'payment_date');
        $bad(['for_month' => ''], 'for_month');
        $bad(['for_month' => today()->addMonth()->format('Y-m')], 'for_month');           // not after this month
        $bad(['for_month' => 'October'], 'for_month');
        $bad(['mode' => ''], 'mode');
        $bad(['mode' => 'barter'], 'mode');
        $bad(['mode' => 'upi', 'reference_no' => ''], 'reference_no');
        $bad(['mode' => 'cheque', 'reference_no' => null], 'reference_no');
        $bad(['for_month' => $this->asha->joining_date->copy()->subMonths(2)->format('Y-m')], 'for_month');   // before they joined

        $this->assertSame(0, SalaryPayment::count());
    }

    public function test_inactive_employee_cannot_be_newly_paid_but_old_entries_stay_editable(): void
    {
        $p = SalaryPayment::create(['employee_id' => $this->asha->id, 'payment_date' => today()->toDateString(), 'for_month' => today()->startOfMonth()->toDateString(), 'amount' => 100, 'mode' => 'cash']);
        $this->asha->delete();

        $this->as('admin')->post('/salary-payments', $this->salary())->assertSessionHasErrors('employee_id');
        $this->as('admin')->get("/salary-payments/{$p->id}/edit")->assertOk();
        $this->as('admin')->get('/salary-payments')->assertOk();
    }

    public function test_edit_and_delete_salary_entry_and_per_employee_history(): void
    {
        $this->as('admin')->post('/salary-payments', $this->salary());
        $this->as('admin')->post('/salary-payments', $this->salary(['for_month' => today()->subMonth()->format('Y-m'), 'amount' => '18000', 'mode' => 'cash']));
        $this->as('admin')->post('/salary-payments', $this->salary(['employee_id' => $this->ravi->id, 'amount' => '9999']));
        $p = SalaryPayment::where('amount', 18500)->firstOrFail();

        $this->as('admin')->put("/salary-payments/{$p->id}", $this->salary(['amount' => '19000']))->assertSessionHasNoErrors();
        $this->assertSame('19000.00', $p->fresh()->amount);

        $this->as('admin')->get("/employees/{$this->asha->id}")->assertOk()
            ->assertSee('₹19,000.00')->assertSee('₹18,000.00')->assertDontSee('₹9,999.00')->assertSee('₹37,000.00');   // history + total, only this employee

        $this->as('admin')->delete("/salary-payments/{$p->id}");
        $this->assertSoftDeleted($p);
        $this->assertSame(2, SalaryPayment::count());
    }

    public function test_employee_page_shows_the_month_attendance(): void
    {
        Attendance::create(['employee_id' => $this->asha->id, 'attendance_date' => today()->startOfMonth()->toDateString(), 'status' => 'present']);

        $this->as('admin')->get("/employees/{$this->asha->id}")->assertOk()->assertSee('Attendance')->assertSee('bg-success');
        $this->as('admin')->get("/employees/{$this->asha->id}?month=2020-01")->assertOk();
    }

    // --- dashboard ----------------------------------------------------------------------

    public function test_dashboard_staff_present_counts_present_and_half_day(): void
    {
        $third = Employee::factory()->create();
        $this->mark(today()->toDateString(), [
            $this->asha->id => ['status' => 'present'], $this->ravi->id => ['status' => 'half_day'], $third->id => ['status' => 'absent'],
        ]);
        Attendance::create(['employee_id' => $third->id, 'attendance_date' => today()->subDay()->toDateString(), 'status' => 'present']);   // yesterday does not count

        $this->assertSame(2, app(DashboardService::class)->summary()['cards']['staff_present']);
    }

    // --- lists --------------------------------------------------------------------------

    public function test_employee_and_salary_lists_search_and_total(): void
    {
        $this->as('admin')->post('/salary-payments', $this->salary());
        $this->as('admin')->post('/salary-payments', $this->salary(['employee_id' => $this->ravi->id, 'amount' => '1500.50', 'mode' => 'cash']));
        $cols = ['payment_date', 'employee', 'for_month', 'amount', 'mode', 'reference_no', 'actions'];
        $ns = ['payment_date', 'for_month', 'amount', 'mode', 'actions'];

        $this->assertSame(1, $this->dt('/salary-payments', $cols, 'Ravi', [], $ns)->json('recordsFiltered'));
        $this->assertSame(0, $this->dt('/salary-payments', $cols, 'nobody', [], $ns)->json('recordsFiltered'));
        $this->assertSame(1, $this->dt('/salary-payments', $cols, 'NEFT', [], $ns)->json('recordsFiltered'));
        $this->assertSame('₹20,000.50', $this->dt('/salary-payments', $cols, '', [], $ns)->json('total'));
        $this->assertSame('₹1,500.50', $this->dt('/salary-payments', $cols, '', ['employee_id' => $this->ravi->id], $ns)->json('total'));
        $this->assertSame('₹0.00', $this->dt('/salary-payments', $cols, '', ['month' => today()->subYear()->format('Y-m')], $ns)->json('total'));

        $ecols = ['employee_code', 'name', 'designation', 'phone', 'joining_date', 'is_active', 'actions'];
        $ens = ['joining_date', 'is_active', 'actions'];
        $this->assertSame(1, $this->dt('/employees', $ecols, 'EMP-002', [], $ens)->json('recordsFiltered'));
        $this->assertSame(0, $this->dt('/employees', $ecols, 'zzz', [], $ens)->json('recordsFiltered'));
        foreach ($ecols as $i => $c) {
            if ($c !== 'actions') {
                $this->dt('/employees', $ecols, '', [], $ens, $i, 'desc')->assertOk();
                $this->dt('/salary-payments', $cols, '', [], $ns, $i, 'desc')->assertOk();
            }
        }
    }
}
