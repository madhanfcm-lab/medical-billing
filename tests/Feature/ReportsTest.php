<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Exports\ReportExport;
use App\Models\Attendance;
use App\Models\Doctor;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\SalaryPayment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportFormatter;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportSection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * A small clinic with known numbers:
 *
 *   Invoice A  Asha / Dr Rao   10,000 - 1,000 discount = 9,000   paid 4,000 UPI (Rita) + 1,000 cash (Raj)   balance 4,000
 *   Invoice B  Ravi / Dr Iyer   2,000                              paid 2,000 cash (Rita)                     balance 0
 *   Invoice C  Asha / Dr Rao      500   cancelled
 *   EMI plan   Ravi   6,000, 1,000 down (admin) + 1,000 instalment (Rita); 5 x 1,000 started 3 months ago
 *
 * Collected 9,000 in 5 receipts: cash 5,000 (4), UPI 4,000 (1). Rita 7,000 (3), Raj 1,000 (1), admin 1,000 (1).
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rita;

    private User $raj;

    private Patient $asha;

    private Patient $ravi;

    private Doctor $rao;

    private Doctor $iyer;

    private EmiPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin User'])->assignRole('admin');
        $this->rita = User::factory()->create(['name' => 'Rita'])->assignRole('receptionist');
        $this->raj = User::factory()->create(['name' => 'Raj'])->assignRole('receptionist');
        $this->asha = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $this->ravi = Patient::factory()->create(['name' => 'Ravi Kumar', 'phone' => '9811122233']);
        $this->rao = Doctor::factory()->create(['name' => 'Dr. Rao']);
        $this->iyer = Doctor::factory()->create(['name' => 'Dr. Iyer']);
    }

    private function seedClinic(): void
    {
        $svc = Service::factory()->create(['default_price' => 10000]);
        $bill = fn (User $by, Patient $p, Doctor $d, string $price, array $extra = []) => $this->actingAs($by)->post('/invoices', array_merge([
            'patient_id' => $p->id, 'doctor_id' => $d->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => $price]],
        ], $extra))->assertSessionHasNoErrors();

        $bill($this->rita, $this->asha, $this->rao, '10000', ['discount_type' => 'amount', 'discount_value' => 1000, 'discount_reason' => 'Loyal', 'pay_amount' => 4000, 'pay_mode' => 'upi', 'pay_reference' => 'UPI1']);
        $a = Invoice::where('invoice_number', 'like', '%00001')->firstOrFail();
        $this->actingAs($this->raj)->post("/invoices/{$a->id}/payments", ['amount' => 1000, 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertSessionHasNoErrors();

        $bill($this->rita, $this->ravi, $this->iyer, '2000', ['pay_amount' => 2000, 'pay_mode' => 'cash']);
        $bill($this->rita, $this->asha, $this->rao, '500');
        $c = Invoice::latest('id')->first();
        $this->actingAs($this->admin)->post("/invoices/{$c->id}/cancel", ['reason' => 'Duplicate bill'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/emi-plans', [
            'patient_id' => $this->ravi->id, 'treatment_name' => 'Aligners', 'total_amount' => 6000, 'down_payment' => 1000, 'pay_mode' => 'cash',
            'calc_by' => 'monthly', 'monthly_amount' => 1000, 'start_date' => today()->subMonths(3)->startOfMonth()->toDateString(), 'due_day' => 15,
        ])->assertSessionHasNoErrors();
        $this->plan = EmiPlan::firstOrFail();
        $this->actingAs($this->rita)->post("/emi-plans/{$this->plan->id}/payments", ['amount' => 1000, 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertSessionHasNoErrors();
    }

    private function def(string $key): Report
    {
        return ReportRegistry::find($key);
    }

    private function report(string $key, array $input = [], ?int $limit = null): ReportResult
    {
        $def = $this->def($key);

        return $def->run(ReportFilters::for($def, $input), $limit);
    }

    private function section(ReportResult $r, string $title): ReportSection
    {
        return collect($r->sections)->firstWhere('title', $title) ?? $this->fail("No section '{$title}'");
    }

    private function figure(ReportResult $r, string $label): mixed
    {
        return collect($r->summary)->firstWhere('label', $label)['value'] ?? $this->fail("No figure '{$label}'");
    }

    // --- daily collection ---------------------------------------------------------------

    public function test_daily_collection_totals(): void
    {
        $this->seedClinic();
        $r = $this->report('daily-collection');

        $this->assertSame('9000.00', $this->figure($r, 'Total collected'));
        $this->assertSame(5, $this->figure($r, 'Receipts'));

        $modes = collect($this->section($r, 'By payment mode')->rows)->keyBy('mode');
        $this->assertSame('5000.00', from_paise(to_paise($modes['Cash']['total'])));
        $this->assertSame(4, $modes['Cash']['n']);
        $this->assertSame('4000.00', from_paise(to_paise($modes['UPI']['total'])));

        $staff = collect($this->section($r, 'By staff (collected by)')->rows)->keyBy('staff');
        $this->assertSame(['Rita' => '7000.00', 'Raj' => '1000.00', 'Admin User' => '1000.00'], $staff->map(fn ($s) => from_paise(to_paise($s['total'])))->all());
        $this->assertSame(3, $staff['Rita']['n']);
    }

    public function test_daily_collection_by_day_and_receipts(): void
    {
        $this->seedClinic();
        $r = $this->report('daily-collection');

        $day = $this->section($r, 'By day');
        $this->assertCount(1, $day->rows);
        $this->assertSame(today()->toDateString(), substr((string) $day->rows[0]['date'], 0, 10));
        $this->assertSame(5, $day->rows[0]['n']);
        $this->assertSame('9000.00', $day->rows[0]['total']);
        $this->assertSame('5000.00', from_paise(to_paise($day->rows[0]['cash'])));
        $this->assertSame('9000.00', $day->totals['total']);

        $receipts = $this->section($r, 'Receipts');
        $this->assertCount(5, $receipts->rows);
        $this->assertSame('9000.00', $receipts->totals['amount']);
        $this->assertContains('Aligners'.'' === '' ? '' : $this->plan->plan_number, array_column($receipts->rows, 'against'));   // EMI receipts name the plan
        $this->assertContains('INV-'.now()->year.'-00001', array_column($receipts->rows, 'against'));
    }

    public function test_daily_collection_filters(): void
    {
        $this->seedClinic();

        $upi = $this->report('daily-collection', ['mode' => 'upi']);
        $this->assertSame('4000.00', $this->figure($upi, 'Total collected'));
        $this->assertSame(1, $this->figure($upi, 'Receipts'));

        $none = $this->report('daily-collection', ['from' => today()->subDays(10)->toDateString(), 'to' => today()->subDays(5)->toDateString()]);
        $this->assertSame('0.00', $this->figure($none, 'Total collected'));
        $this->assertSame([], $this->section($none, 'Receipts')->rows);

        $future = $this->report('daily-collection', ['from' => today()->addDay()->toDateString()]);
        $this->assertSame(0, $this->figure($future, 'Receipts'));
        $this->assertStringContainsString('From', $future->subtitle);
    }

    // --- invoices --------------------------------------------------------------------------

    public function test_invoice_report_totals_exclude_cancelled(): void
    {
        $this->seedClinic();
        $r = $this->report('invoices');
        $s = $this->section($r, 'Invoices');

        $this->assertCount(3, $s->rows);                                    // cancelled invoice is listed...
        $this->assertSame(2, $this->figure($r, 'Invoices'));                // ...but not counted
        $this->assertSame('11000.00', $this->figure($r, 'Billed'));
        $this->assertSame('1000.00', $this->figure($r, 'Discount given'));
        $this->assertSame('7000.00', $this->figure($r, 'Collected'));
        $this->assertSame('4000.00', $this->figure($r, 'Balance due'));
        $this->assertSame(1, $this->figure($r, 'Cancelled (excluded)'));
        $this->assertSame('11000.00', $s->totals['total']);
        $this->assertSame('4000.00', $s->totals['balance']);
        $this->assertSame(['Partially Paid', 'Paid', 'Cancelled'], array_column($s->rows, 'status'));
    }

    public function test_invoice_report_filters(): void
    {
        $this->seedClinic();

        $this->assertCount(1, $this->section($this->report('invoices', ['status' => 'paid']), 'Invoices')->rows);
        $this->assertCount(1, $this->section($this->report('invoices', ['status' => 'cancelled']), 'Invoices')->rows);

        $rao = $this->report('invoices', ['doctor_id' => $this->rao->id]);
        $this->assertSame('9000.00', $this->figure($rao, 'Billed'));         // A only (C is cancelled)
        $this->assertStringContainsString('Dr. Rao', $rao->subtitle);

        $this->assertSame(0, $this->figure($this->report('invoices', ['from' => today()->addDay()->toDateString()]), 'Invoices'));
    }

    // --- outstanding ---------------------------------------------------------------------------

    public function test_outstanding_dues_per_patient(): void
    {
        $this->seedClinic();
        $r = $this->report('outstanding-dues');
        $rows = collect($this->section($r, 'Patient-wise outstanding')->rows)->keyBy('patient');

        $this->assertCount(2, $rows);
        $this->assertSame('4000.00', $rows['Asha Verma']['invoice_balance']);   // invoice A; cancelled C is excluded
        $this->assertSame('0.00', $rows['Asha Verma']['emi_balance']);
        $this->assertSame('4000.00', $rows['Asha Verma']['total']);
        $this->assertSame(1, $rows['Asha Verma']['invoices']);

        $this->assertSame('0.00', $rows['Ravi Kumar']['invoice_balance']);     // invoice B is fully paid
        $this->assertSame('4000.00', $rows['Ravi Kumar']['emi_balance']);      // 5 x 1000 financed, 1 paid
        $this->assertSame('4000.00', $rows['Ravi Kumar']['total']);

        $overdue = EmiInstallment::overdue()->get()->sum(fn ($i) => to_paise($i->balance));
        $this->assertGreaterThan(0, $overdue);
        $this->assertSame(from_paise($overdue), from_paise(to_paise($rows['Ravi Kumar']['overdue_emi'])));
        $this->assertSame('0.00', from_paise(to_paise($rows['Asha Verma']['overdue_emi'])));

        $this->assertSame('8000.00', $this->figure($r, 'Total outstanding'));
        $this->assertSame(2, $this->figure($r, 'Patients with dues'));
        $this->assertCount(1, $this->section($r, 'Invoices with a balance')->rows);
    }

    public function test_outstanding_does_not_double_count_invoice_linked_plans(): void
    {
        $svc = Service::factory()->create();
        $this->actingAs($this->rita)->post('/invoices', ['patient_id' => $this->asha->id, 'invoice_date' => today()->toDateString(), 'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 9000]]]);
        $inv = Invoice::firstOrFail();
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->asha->id, 'invoice_id' => $inv->id, 'treatment_name' => 'Crown', 'total_amount' => 9000, 'down_payment' => 0,
            'calc_by' => 'months', 'months' => 3, 'start_date' => today()->toDateString(), 'due_day' => 15]);

        $r = $this->report('outstanding-dues');
        $this->assertSame('9000.00', $this->figure($r, 'Total outstanding'));   // not 18000
        $this->assertSame('0.00', $this->figure($r, 'Stand-alone EMI balances'));
    }

    public function test_outstanding_period_limits_to_invoices_in_range(): void
    {
        $this->seedClinic();
        $r = $this->report('outstanding-dues', ['from' => today()->addDay()->toDateString(), 'to' => today()->addDays(5)->toDateString()]);

        $this->assertSame('0.00', $this->figure($r, 'Total outstanding'));
        $this->assertSame([], $this->section($r, 'Patient-wise outstanding')->rows);
    }

    // --- EMI ---------------------------------------------------------------------------------------

    public function test_emi_report(): void
    {
        $this->seedClinic();
        $range = ['from' => today()->subMonths(6)->toDateString(), 'to' => today()->toDateString()];
        $r = $this->report('emi', $range);
        $row = $this->section($r, 'EMI plans')->rows[0];

        $this->assertSame($this->plan->plan_number, $row['plan']);
        $this->assertSame('6000.00', $row['total']);
        $this->assertSame('1000.00', $row['down']);
        $this->assertSame('1000.00', $row['paid']);
        $this->assertSame('4000.00', $row['balance']);
        $this->assertGreaterThan(0, to_paise($row['overdue']));
        $this->assertSame('Active', $row['status']);
        $this->assertSame(1, $this->figure($r, 'Plans'));
        $this->assertSame('2000.00', $this->figure($r, 'Received so far'));
        $this->assertSame('4000.00', $this->figure($r, 'Still to collect'));
        $this->assertSame(1, $this->figure($r, 'Active plans'));
        $this->assertSame(1, $this->figure($r, 'Plans with overdue instalments'));
        $this->assertSame($row['overdue'], $this->figure($r, 'Overdue amount'));
    }

    public function test_emi_report_status_filters_including_overdue(): void
    {
        $this->seedClinic();
        $range = ['from' => today()->subMonths(6)->toDateString()];

        $this->assertCount(1, $this->section($this->report('emi', $range + ['status' => 'overdue']), 'EMI plans')->rows);
        $this->assertCount(1, $this->section($this->report('emi', $range + ['status' => 'active']), 'EMI plans')->rows);
        $this->assertCount(0, $this->section($this->report('emi', $range + ['status' => 'completed']), 'EMI plans')->rows);

        $this->actingAs($this->admin)->post("/emi-plans/{$this->plan->id}/cancel", ['reason' => 'Patient left']);
        $cancelled = $this->report('emi', $range + ['status' => 'cancelled']);
        $this->assertCount(1, $this->section($cancelled, 'EMI plans')->rows);
        $this->assertSame('0.00', from_paise(to_paise($this->section($cancelled, 'EMI plans')->rows[0]['overdue'])));   // cancelled plans are not "overdue"
        $this->assertCount(0, $this->section($this->report('emi', $range + ['status' => 'overdue']), 'EMI plans')->rows);
    }

    // --- follow-ups --------------------------------------------------------------------------------

    public function test_follow_up_report_outcomes(): void
    {
        $mk = fn (array $o) => FollowUp::factory()->create(array_merge(['patient_id' => $this->asha->id, 'doctor_id' => $this->rao->id], $o));
        $mk(['status' => FollowUpStatus::Completed, 'follow_up_date' => today()->toDateString()]);
        $mk(['status' => FollowUpStatus::Missed, 'follow_up_date' => today()->subDays(3)->toDateString()]);
        $mk(['status' => FollowUpStatus::Scheduled, 'follow_up_date' => today()->subDay()->toDateString()]);   // past, not yet flagged = missed
        $mk(['status' => FollowUpStatus::Scheduled, 'follow_up_date' => today()->addDays(2)->toDateString()]);
        $mk(['status' => FollowUpStatus::Cancelled, 'follow_up_date' => today()->addDays(3)->toDateString()]);
        $other = FollowUp::factory()->create(['doctor_id' => $this->iyer->id, 'follow_up_date' => today()->toDateString(), 'status' => FollowUpStatus::Completed]);

        $range = ['from' => today()->subDays(10)->toDateString(), 'to' => today()->addDays(10)->toDateString()];
        $r = $this->report('follow-ups', $range);
        $this->assertSame(6, $this->figure($r, 'Follow-ups'));
        $this->assertSame(2, $this->figure($r, 'Completed'));
        $this->assertSame(2, $this->figure($r, 'Missed'));
        $this->assertSame('50%', $this->figure($r, 'Kept (completed ÷ completed + missed)'));

        $byOutcome = collect($this->section($r, 'By outcome')->rows)->pluck('n', 'status');
        $this->assertSame(1, $byOutcome['Scheduled (upcoming)']);
        $this->assertSame(1, $byOutcome['Cancelled']);

        $rao = $this->report('follow-ups', $range + ['doctor_id' => $this->rao->id]);
        $this->assertSame(5, $this->figure($rao, 'Follow-ups'));
        $this->assertSame('33%', $this->figure($rao, 'Kept (completed ÷ completed + missed)'));

        $missed = $this->report('follow-ups', $range + ['status' => 'missed']);
        $this->assertCount(2, $this->section($missed, 'Follow-ups')->rows);
        $this->assertSame(['Missed', 'Missed'], array_column($this->section($missed, 'Follow-ups')->rows, 'status'));
        $this->assertCount(1, $this->section($this->report('follow-ups', $range + ['status' => 'scheduled']), 'Follow-ups')->rows);
        $this->assertNotNull($other);
    }

    // --- doctor revenue ----------------------------------------------------------------------------

    public function test_doctor_revenue(): void
    {
        $this->seedClinic();
        Visit::factory()->count(2)->create(['patient_id' => $this->asha->id, 'doctor_id' => $this->rao->id]);
        Visit::factory()->create(['patient_id' => $this->ravi->id, 'doctor_id' => $this->iyer->id]);

        $r = $this->report('doctor-revenue');
        $rows = collect($this->section($r, 'Revenue by doctor')->rows)->keyBy('doctor');

        $this->assertSame(['Dr. Rao', 'Dr. Iyer'], $rows->keys()->all());           // highest billing first
        $this->assertSame('9000.00', $rows['Dr. Rao']['billed']);                   // cancelled invoice C is not revenue
        $this->assertSame('1000.00', $rows['Dr. Rao']['discount']);
        $this->assertSame('5000.00', $rows['Dr. Rao']['collected']);
        $this->assertSame('4000.00', $rows['Dr. Rao']['balance']);
        $this->assertSame(1, $rows['Dr. Rao']['invoices']);
        $this->assertSame(2, $rows['Dr. Rao']['visits']);
        $this->assertSame('2000.00', $rows['Dr. Iyer']['billed']);
        $this->assertSame(1, $rows['Dr. Iyer']['visits']);

        $this->assertSame('11000.00', $this->figure($r, 'Billed'));
        $this->assertSame('7000.00', $this->figure($r, 'Collected so far'));
        $this->assertSame(3, $this->figure($r, 'Visits'));

        $one = $this->report('doctor-revenue', ['doctor_id' => $this->iyer->id]);
        $this->assertSame('2000.00', $this->figure($one, 'Billed'));
        $this->assertCount(1, $this->section($one, 'Revenue by doctor')->rows);
    }

    public function test_doctor_revenue_shows_invoices_without_a_doctor(): void
    {
        $svc = Service::factory()->create();
        $this->actingAs($this->rita)->post('/invoices', ['patient_id' => $this->asha->id, 'invoice_date' => today()->toDateString(), 'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 700]]]);

        $rows = $this->section($this->report('doctor-revenue'), 'Revenue by doctor')->rows;
        $this->assertSame('No doctor assigned', $rows[0]['doctor']);
        $this->assertSame('700.00', $rows[0]['billed']);
    }

    // --- attendance --------------------------------------------------------------------------------

    public function test_attendance_report_counts_and_grid(): void
    {
        $a = Employee::factory()->create(['name' => 'Asha Nair', 'employee_code' => 'EMP-001']);
        $b = Employee::factory()->create(['name' => 'Ravi Das', 'employee_code' => 'EMP-002']);
        $start = today()->startOfMonth();
        foreach ([[1, 'present'], [2, 'present'], [3, 'absent'], [4, 'half_day']] as [$d, $s]) {
            Attendance::create(['employee_id' => $a->id, 'attendance_date' => $start->copy()->day($d)->toDateString(), 'status' => $s]);
        }
        Attendance::create(['employee_id' => $b->id, 'attendance_date' => $start->copy()->day(1)->toDateString(), 'status' => 'leave']);

        $r = $this->report('attendance');
        $sum = collect($this->section($r, 'Summary by employee')->rows)->keyBy('name');
        $this->assertSame(['present' => 2, 'absent' => 1, 'half_day' => 1, 'leave' => 0, 'marked' => 4], collect($sum['Asha Nair'])->only(['present', 'absent', 'half_day', 'leave', 'marked'])->all());
        $this->assertSame(1, $sum['Ravi Das']['leave']);
        $this->assertSame(['code' => 'Total', 'present' => 2, 'absent' => 1, 'half_day' => 1, 'leave' => 1, 'marked' => 5], $this->section($r, 'Summary by employee')->totals);

        $grid = $this->section($r, 'Day-by-day');
        $cell = 'd'.$start->copy()->day(3)->format('Ymd');
        $this->assertSame('A', $grid->rows[0][$cell]);
        $this->assertSame(2, $grid->rows[0]['p']);

        $one = $this->report('attendance', ['employee_id' => $b->id]);
        $this->assertCount(1, $this->section($one, 'Summary by employee')->rows);
    }

    public function test_attendance_grid_is_skipped_for_long_ranges(): void
    {
        Employee::factory()->create();
        $long = $this->report('attendance', ['from' => today()->subDays(60)->toDateString(), 'to' => today()->toDateString()]);

        $this->assertSame(['Summary by employee'], array_map(fn ($s) => $s->title, $long->sections));
    }

    // --- salary ----------------------------------------------------------------------------------

    public function test_salary_report(): void
    {
        $e1 = Employee::factory()->create(['name' => 'Asha Nair', 'employee_code' => 'EMP-001']);
        $e2 = Employee::factory()->create(['name' => 'Ravi Das', 'employee_code' => 'EMP-002']);
        $pay = fn (Employee $e, string $amt, string $mode) => SalaryPayment::create(['employee_id' => $e->id, 'payment_date' => today()->toDateString(),
            'for_month' => today()->startOfMonth()->toDateString(), 'amount' => $amt, 'mode' => $mode, 'reference_no' => $mode === 'cash' ? null : 'R1']);
        $pay($e1, '18000', 'bank_transfer');
        $pay($e1, '500.50', 'cash');
        $pay($e2, '12000', 'cash');

        $r = $this->report('salary');
        $this->assertSame('30500.50', $this->figure($r, 'Total paid'));
        $this->assertSame(3, $this->figure($r, 'Payments'));
        $this->assertSame(2, $this->figure($r, 'Employees paid'));

        $by = collect($this->section($r, 'By employee')->rows)->keyBy('name');
        $this->assertSame('18500.50', from_paise(to_paise($by['Asha Nair']['total'])));
        $this->assertSame(2, $by['Asha Nair']['n']);

        $this->assertSame('12500.50', $this->figure($this->report('salary', ['mode' => 'cash']), 'Total paid'));
        $this->assertSame('12000.00', $this->figure($this->report('salary', ['employee_id' => $e2->id]), 'Total paid'));
    }

    // --- screen limit --------------------------------------------------------------------------------

    public function test_on_screen_rows_are_capped_but_totals_and_exports_are_complete(): void
    {
        $this->seedClinic();

        $capped = $this->report('daily-collection', [], 2);
        $receipts = $this->section($capped, 'Receipts');
        $this->assertCount(2, $receipts->rows);
        $this->assertSame(5, $receipts->totalRows);
        $this->assertTrue($receipts->isTruncated());
        $this->assertSame('9000.00', $receipts->totals['amount']);               // totals still cover every row

        $full = $this->section($this->report('daily-collection'), 'Receipts');
        $this->assertCount(5, $full->rows);
        $this->assertFalse($full->isTruncated());
    }

    // --- pages & access --------------------------------------------------------------------------------

    public function test_every_report_renders_with_data_and_when_empty(): void
    {
        foreach ([false, true] as $withData) {
            if ($withData) {
                $this->seedClinic();
            }
            foreach (array_keys(ReportRegistry::all()) as $key) {
                $this->actingAs($this->admin)->get("/reports/{$key}")->assertOk()->assertSee($this->def($key)->title());
            }
        }
    }

    public function test_report_page_shows_figures_and_a_truncation_note_is_absent_for_small_reports(): void
    {
        $this->seedClinic();
        $this->actingAs($this->admin)->get('/reports/invoices')->assertOk()
            ->assertSee('₹11,000.00')->assertSee('Cancelled (excluded)')->assertSee('INV-'.now()->year.'-00003')->assertDontSee('Showing the first');
        $this->actingAs($this->admin)->get('/reports/daily-collection')->assertOk()->assertSee('₹9,000.00')->assertSee('Rita');
    }

    public function test_reports_index_lists_all_reports(): void
    {
        $res = $this->actingAs($this->admin)->get('/reports')->assertOk();
        foreach (ReportRegistry::all() as $key => $def) {
            $res->assertSee($def->title())->assertSee(route('reports.show', $key), false);
        }
        $this->assertCount(8, ReportRegistry::all());
    }

    public function test_only_admin_can_use_reports(): void
    {
        foreach (['/reports', '/reports/invoices', '/reports/invoices/excel', '/reports/invoices/pdf'] as $url) {
            $this->actingAs($this->rita)->get($url)->assertForbidden();
            $this->actingAs(User::factory()->create()->assignRole('doctor'))->get($url)->assertForbidden();
            $this->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get('/reports')->assertRedirect(route('login'));
    }

    public function test_unknown_report_is_404_and_bad_filters_are_rejected(): void
    {
        $this->actingAs($this->admin)->get('/reports/nope')->assertNotFound();
        $this->actingAs($this->admin)->get('/reports/nope/excel')->assertNotFound();

        $this->actingAs($this->admin)->get('/reports/invoices?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->get('/reports/invoices?from=garbage')->assertSessionHasErrors('from');
        $this->actingAs($this->admin)->get('/reports/invoices?doctor_id=9999')->assertSessionHasErrors('doctor_id');
        $this->actingAs($this->admin)->get('/reports/daily-collection?mode=barter')->assertSessionHasErrors('mode');
        $this->actingAs($this->admin)->get('/reports/invoices/excel?to=2020-01-01&from=2021-01-01')->assertSessionHasErrors('to');
    }

    public function test_filters_a_report_does_not_use_are_ignored(): void
    {
        $this->seedClinic();
        $r = $this->report('salary', ['doctor_id' => $this->rao->id, 'status' => 'paid']);   // salary has no doctor / status filter

        $this->assertSame('0.00', $this->figure($r, 'Total paid'));
        $this->assertStringNotContainsString('Dr. Rao', $r->subtitle);
    }

    // --- exports ----------------------------------------------------------------------------------------

    private function loadXlsx(string $binary): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        file_put_contents($path, $binary);
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_excel_has_a_summary_and_one_sheet_per_table_with_native_values(): void
    {
        $this->seedClinic();
        $book = $this->loadXlsx(Excel::raw(new ReportExport($this->report('daily-collection')), ExcelWriter::XLSX));

        $this->assertSame(['Summary', 'By day', 'By payment mode', 'By staff (collected by)', 'Receipts'], $book->getSheetNames());

        $summary = $book->getSheetByName('Summary');
        $this->assertSame('Daily Collection', $summary->getCell('A1')->getValue());
        $this->assertSame('Total collected', $summary->getCell('A5')->getValue());
        $this->assertEquals(9000.0, $summary->getCell('B5')->getValue());                       // a number, not text
        $this->assertSame('#,##0.00', $summary->getStyle('B5')->getNumberFormat()->getFormatCode());

        $modes = $book->getSheetByName('By payment mode');
        $this->assertSame(['Mode', 'Receipts', 'Amount'], [$modes->getCell('A4')->getValue(), $modes->getCell('B4')->getValue(), $modes->getCell('C4')->getValue()]);
        $this->assertSame('Cash', $modes->getCell('A5')->getValue());
        $this->assertEquals(5000.0, $modes->getCell('C5')->getValue());
        $this->assertSame('Total', $modes->getCell('A7')->getValue());
        $this->assertEquals(9000.0, $modes->getCell('C7')->getValue());

        $receipts = $book->getSheetByName('Receipts');
        $dateCell = $receipts->getCell('B5');
        $this->assertTrue(is_numeric($dateCell->getValue()));                                 // a real Excel date
        $this->assertSame('dd-mm-yyyy', $receipts->getStyle('B5')->getNumberFormat()->getFormatCode());
        $this->assertSame(today()->format('d-m-Y'), Date::excelToDateTimeObject($dateCell->getValue())->format('d-m-Y'));
        $this->assertSame(4 + 5 + 1, $receipts->getHighestDataRow());                         // heading row + 5 receipts + totals
    }

    public function test_excel_download_for_every_report_opens(): void
    {
        foreach ([false, true] as $withData) {
            if ($withData) {
                $this->seedClinic();
            }
            foreach (array_keys(ReportRegistry::all()) as $key) {
                $res = $this->actingAs($this->admin)->get("/reports/{$key}/excel")->assertOk();
                $this->assertStringContainsString('.xlsx', $res->headers->get('Content-Disposition'));
                $this->assertStringContainsString($key, $res->headers->get('Content-Disposition'));

                $book = IOFactory::load($res->baseResponse->getFile()->getPathname());
                $this->assertGreaterThanOrEqual(1, $book->getSheetCount(), $key);
            }
        }
    }

    public function test_excel_export_honours_filters_and_is_not_capped(): void
    {
        $this->seedClinic();
        $res = $this->actingAs($this->admin)->get('/reports/daily-collection/excel?mode=upi')->assertOk();
        $book = IOFactory::load($res->baseResponse->getFile()->getPathname());

        $this->assertEquals(4000.0, $book->getSheetByName('Summary')->getCell('B5')->getValue());
        $this->assertStringContainsString('Mode: UPI', (string) $book->getSheetByName('Summary')->getCell('A2')->getValue());
        $this->assertSame(1, $book->getSheetByName('Receipts')->getHighestDataRow() - 5);    // 1 receipt row beyond the heading + totals
    }

    public function test_sheet_names_are_valid_and_unique(): void
    {
        $r = new ReportResult('Odd', 'x', [], [
            new ReportSection('A/very:long*name?[that]exceeds thirty-one characters', [ReportSection::column('a', 'A')], [['a' => 1]]),
            new ReportSection('A/very:long*name?[that]exceeds thirty-one characters', [ReportSection::column('a', 'A')], [['a' => 2]]),
        ]);
        $names = $this->loadXlsx(Excel::raw(new ReportExport($r), ExcelWriter::XLSX))->getSheetNames();

        $this->assertCount(2, array_unique(array_map('mb_strtolower', $names)));
        foreach ($names as $n) {
            $this->assertLessThanOrEqual(31, mb_strlen($n));
            $this->assertDoesNotMatchRegularExpression('/[:\\\\\/?*\[\]]/', $n);
        }
    }

    public function test_pdf_download_for_every_report(): void
    {
        foreach ([false, true] as $withData) {
            if ($withData) {
                $this->seedClinic();
            }
            foreach (array_keys(ReportRegistry::all()) as $key) {
                $res = $this->actingAs($this->admin)->get("/reports/{$key}/pdf")->assertOk();
                $this->assertSame('application/pdf', $res->headers->get('Content-Type'), $key);
                $this->assertStringStartsWith('%PDF', $res->getContent());
                $this->assertStringContainsString('.pdf', $res->headers->get('Content-Disposition'));
            }
        }
    }

    public function test_formatter(): void
    {
        $this->assertSame('₹1,23,456.50', ReportFormatter::display('123456.5', 'money'));
        $this->assertSame('1,234', ReportFormatter::display(1234, 'int'));
        $this->assertSame('15-10-2026', ReportFormatter::display('2026-10-15', 'date'));
        $this->assertSame('—', ReportFormatter::display(null, 'money'));
        $this->assertSame(12.35, ReportFormatter::excel('12.345', 'money') + 0.0 === 12.35 ? 12.35 : ReportFormatter::excel('12.345', 'money'));
        $this->assertSame(7, ReportFormatter::excel('7.0', 'int'));
        $this->assertNull(ReportFormatter::excel('', 'text'));
        $this->assertTrue(ReportFormatter::isNumeric('money'));
        $this->assertFalse(ReportFormatter::isNumeric('date'));
    }
}
