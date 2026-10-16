<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\EmployeeService;
use App\Services\InvoiceService;
use App\Support\CurrentBranch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

/** One hospital, several branches: who can work where, and that branch data stays in its branch. */
class BranchTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private Branch $main;

    private Branch $north;

    private User $admin;

    private User $rita;

    private Patient $patient;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->main = Branch::main();
        $this->north = Branch::create(['name' => 'North Branch', 'code' => 'NOR', 'address' => '9 North Road', 'phone' => '0201111111', 'is_active' => true]);
        $this->admin = User::factory()->create(['name' => 'Admin'])->assignRole('admin');
        $this->rita = User::factory()->create(['name' => 'Rita'])->assignRole('receptionist');   // main only
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma']);
        $this->doctor = Doctor::factory()->create(['name' => 'Dr. Rao', 'consultation_fee' => 0]);
    }

    /** Do something as if working inside a branch (the way a request would). */
    private function in(Branch $branch, callable $do): mixed
    {
        $current = app(CurrentBranch::class);
        $current->set($branch, $branch);
        try {
            return $do();
        } finally {
            $current->clear();
        }
    }

    /** The same-named patient registered in a branch: branches never share patients. */
    private function patientIn(Branch $branch, string $name = 'Asha Verma'): Patient
    {
        return $branch->is($this->main) && $name === 'Asha Verma' ? $this->patient : $this->in($branch, fn () => Patient::factory()->create(['name' => $name]));
    }

    private function billIn(Branch $branch): Invoice
    {
        $patient = $this->patientIn($branch);
        $service = Service::factory()->create(['default_price' => 500, 'gst_percent' => 0]);

        return $this->in($branch, fn () => app(InvoiceService::class)->create([
            'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $service->id, 'quantity' => 1, 'unit_price' => 500, 'discount_amount' => 0, 'gst_percent' => 0]], 'pay_amount' => 0,
        ]));
    }

    private function switchTo(User $user, Branch|string $branch)
    {
        return $this->actingAs($user)->post('/branch/switch', ['branch' => $branch instanceof Branch ? $branch->id : $branch]);
    }

    // ═══ who can work where ═══

    public function test_existing_data_lives_in_the_first_branch_and_logins_start_there(): void
    {
        $this->assertSame('Main Branch', $this->main->name);
        $this->actingAs($this->rita)->get('/dashboard')->assertOk()->assertDontSee('name="branch"', false);   // one branch: no switcher
    }

    public function test_a_login_with_no_branch_is_refused_with_a_clear_message(): void
    {
        $nobody = User::factory()->create()->assignRole('receptionist');
        $nobody->branches()->detach();

        $this->actingAs($nobody)->get('/dashboard')->assertForbidden()->assertSee('No branch is assigned', false);
    }

    public function test_a_receptionist_cannot_switch_to_a_branch_she_does_not_work_in(): void
    {
        $this->switchTo($this->rita, $this->north)->assertForbidden();

        $this->rita->branches()->attach($this->north);
        $this->switchTo($this->rita, $this->north)->assertRedirect()->assertSessionHas('success');
    }

    public function test_admin_can_use_every_branch_and_the_top_bar_shows_the_switcher(): void
    {
        $page = $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $page->assertSee('Main Branch')->assertSee('North Branch')->assertSee('All branches');
        $this->switchTo($this->admin, $this->north)->assertSessionHas('success');
    }

    public function test_an_inactive_branch_is_not_offered_or_accepted(): void
    {
        $this->north->update(['is_active' => false]);

        $this->switchTo($this->admin, $this->north)->assertForbidden();
        $this->assertCount(1, $this->admin->accessibleBranches());
    }

    // ═══ data stays inside its branch ═══

    public function test_a_bill_is_only_visible_in_its_own_branch(): void
    {
        $inNorth = $this->billIn($this->north);
        $inMain = $this->billIn($this->main);
        $this->assertSame($this->north->id, $inNorth->branch_id);

        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->get("/invoices/{$inMain->id}")->assertOk();
        $this->actingAs($this->admin)->get("/invoices/{$inNorth->id}")->assertNotFound();
        $this->dt('/invoices', ['invoice_number', 'patient', 'doctor', 'grand_total', 'paid_amount', 'balance_amount', 'status'], '', [], ['patient', 'doctor'])
            ->assertJsonPath('recordsTotal', 1);

        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->get("/invoices/{$inNorth->id}")->assertOk();
        $this->actingAs($this->admin)->get("/invoices/{$inMain->id}")->assertNotFound();
    }

    public function test_all_branches_shows_everything_and_new_records_go_to_the_home_branch(): void
    {
        $this->billIn($this->north);
        $this->billIn($this->main);

        $this->switchTo($this->admin, 'all')->assertSessionHas('success');
        $this->actingAs($this->admin)->get('/invoices')->assertOk();
        $this->dt('/invoices', ['invoice_number', 'patient', 'doctor', 'grand_total', 'paid_amount', 'balance_amount', 'status'], '', [], ['patient', 'doctor'])
            ->assertJsonPath('recordsTotal', 2);
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('viewing')->assertSee('all branches');

        $this->actingAs($this->admin)->post('/visits', ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(), 'chief_complaint' => 'Pain'])->assertSessionHasNoErrors();
        $this->assertSame($this->main->id, Visit::withoutGlobalScopes()->latest('id')->first()->branch_id);   // lowest-id branch is home
    }

    public function test_new_records_are_stamped_with_the_branch_being_worked_in(): void
    {
        $northPatient = $this->patientIn($this->north);
        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->post('/visits', ['patient_id' => $northPatient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(), 'chief_complaint' => 'Pain'])->assertSessionHasNoErrors();

        $this->assertSame($this->north->id, Visit::withoutGlobalScopes()->latest('id')->first()->branch_id);
        $this->switchTo($this->admin, $this->main);
        $this->dt('/visits', ['visit_date', 'patient', 'doctor', 'chief_complaint'], '', [], ['patient', 'doctor'])->assertJsonPath('recordsTotal', 0);
        $this->switchTo($this->admin, $this->north);
        $this->dt('/visits', ['visit_date', 'patient', 'doctor', 'chief_complaint'], '', [], ['patient', 'doctor'])->assertJsonPath('recordsTotal', 1);
    }

    public function test_each_branch_has_its_own_patients(): void
    {
        $northPatient = $this->patientIn($this->north, 'Nora North');
        $this->assertSame($this->north->id, $northPatient->branch_id);

        $list = fn () => $this->dt('/patients', ['uhid', 'name', 'phone', 'gender', 'age', 'balance'], '', [], ['gender', 'age', 'balance']);
        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->get("/patients/{$this->patient->id}")->assertOk()->assertSee('Asha Verma');
        $this->actingAs($this->admin)->get("/patients/{$northPatient->id}")->assertNotFound();
        $this->assertSame(1, $list()->json('recordsTotal'));
        $this->actingAs($this->admin)->getJson('/patients/search?q=Nora')->assertOk()->assertJsonCount(0, 'results');

        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->get("/patients/{$northPatient->id}")->assertOk();
        $this->actingAs($this->admin)->get("/patients/{$this->patient->id}")->assertNotFound();
        $this->assertSame(1, $list()->json('recordsTotal'));
        $this->actingAs($this->admin)->getJson('/patients/search?q=Nora')->assertOk()->assertJsonCount(1, 'results');

        $this->switchTo($this->admin, 'all');
        $this->assertSame(2, $list()->json('recordsTotal'));
    }

    public function test_a_patient_registered_in_a_branch_belongs_to_it_and_cannot_be_used_in_another(): void
    {
        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->post('/patients', ['name' => 'Nina New', 'gender' => 'female', 'phone' => '9811122200'])->assertSessionHasNoErrors();
        $nina = Patient::withoutGlobalScopes()->where('name', 'Nina New')->firstOrFail();
        $this->assertSame($this->north->id, $nina->branch_id);

        // a bill / visit / appointment for her cannot be made from the other branch
        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->post('/visits', ['patient_id' => $nina->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->toDateString(), 'chief_complaint' => 'x'])->assertSessionHasErrors('patient_id');
    }

    public function test_the_patient_page_only_lists_bills_from_the_branch_in_use(): void
    {
        $mainBill = $this->billIn($this->main);
        $northBill = $this->billIn($this->north);

        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->get("/patients/{$this->patient->id}")->assertOk()->assertSee($mainBill->invoice_number)->assertDontSee($northBill->invoice_number);
        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->get("/patients/{$northBill->patient_id}")->assertOk()->assertSee($northBill->invoice_number)->assertDontSee($mainBill->invoice_number);
    }

    public function test_document_numbers_stay_unique_across_branches(): void
    {
        $a = $this->billIn($this->main);
        $b = $this->billIn($this->north);

        $this->assertNotSame($a->invoice_number, $b->invoice_number);
    }

    public function test_reports_and_the_dashboard_count_only_the_current_branch(): void
    {
        $this->billIn($this->north);
        $this->billIn($this->north);
        $this->billIn($this->main);

        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('₹500');
        $this->actingAs($this->admin)->get('/reports/invoices?from='.today()->toDateString())->assertOk()->assertSee('₹500')->assertDontSee('₹1,500');
        $this->switchTo($this->admin, 'all');
        $this->actingAs($this->admin)->get('/reports/invoices?from='.today()->toDateString())->assertOk()->assertSee('₹1,500');
    }

    public function test_staff_attendance_is_per_branch(): void
    {
        $mainStaff = Employee::factory()->create(['name' => 'Main Nurse']);
        $northStaff = $this->in($this->north, fn () => Employee::factory()->create(['name' => 'North Nurse']));
        $this->assertSame($this->north->id, $northStaff->branch_id);

        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->get('/attendance')->assertOk()->assertSee('North Nurse')->assertDontSee('Main Nurse');
        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->get('/attendance')->assertOk()->assertSee('Main Nurse')->assertDontSee('North Nurse');
        $this->assertSame(0, Attendance::count());
        $this->assertNotNull($mainStaff);
    }

    public function test_a_login_is_one_person_across_branches_when_adding_to_employees(): void
    {
        $rita = $this->rita;
        $this->in($this->north, fn () => app(EmployeeService::class)->createForUser($rita));

        // seen from the main branch the staff record is not visible, but she must not be offered / added a second time
        $this->switchTo($this->admin, $this->main);
        $this->actingAs($this->admin)->post('/employees/sync-logins')->assertRedirect();
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('user_id', $rita->id)->count());
    }

    public function test_printed_documents_carry_the_branch_address(): void
    {
        $bill = $this->billIn($this->north);

        $this->switchTo($this->admin, $this->north);
        $this->actingAs($this->admin)->get("/invoices/{$bill->id}/print")->assertOk()->assertSee('North Branch')->assertSee('9 North Road')->assertSee('0201111111');
    }

    // ═══ branches and users: admin screens ═══

    public function test_only_the_admin_manages_branches(): void
    {
        $this->actingAs($this->rita)->get('/branches')->assertForbidden();
        $this->actingAs($this->admin)->get('/branches')->assertOk()->assertSee('Each branch keeps its own');
        $this->actingAs($this->admin)->getJson('/branches', ['X-Requested-With' => 'XMLHttpRequest'])->assertJsonPath('recordsTotal', 2);
    }

    public function test_admin_adds_and_edits_a_branch(): void
    {
        $this->actingAs($this->admin)->post('/branches', ['name' => 'East Branch', 'code' => 'ea', 'is_active' => 1])->assertSessionHasNoErrors()->assertRedirect(route('branches.index'));
        $east = Branch::where('name', 'East Branch')->firstOrFail();
        $this->assertSame('EA', $east->code);

        $this->actingAs($this->admin)->put("/branches/{$east->id}", ['name' => 'East Wing', 'code' => 'EA', 'phone' => '0202222222', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('East Wing', $east->fresh()->name);
    }

    public function test_branch_code_must_be_unique_and_the_last_active_branch_cannot_be_switched_off(): void
    {
        $this->actingAs($this->admin)->post('/branches', ['name' => 'Copy', 'code' => 'MAIN', 'is_active' => 1])->assertSessionHasErrors('code');

        $this->north->update(['is_active' => false]);
        $this->actingAs($this->admin)->put("/branches/{$this->main->id}", ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 0])->assertSessionHasErrors('is_active');
        $this->assertTrue($this->main->fresh()->is_active);
    }

    public function test_a_non_admin_login_needs_a_branch_and_admins_do_not(): void
    {
        $base = ['name' => 'New Person', 'email' => 'new@clinic.test', 'role' => 'receptionist', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'is_active' => 1];

        $this->actingAs($this->admin)->post('/users', $base)->assertSessionHasErrors('branches');
        $this->actingAs($this->admin)->post('/users', $base + ['branches' => [$this->north->id]])->assertSessionHasNoErrors();
        $user = User::where('email', 'new@clinic.test')->firstOrFail();
        $this->assertSame([$this->north->id], $user->branches()->pluck('branches.id')->all());

        $this->actingAs($this->admin)->post('/users', ['email' => 'boss@clinic.test', 'name' => 'Boss', 'role' => 'admin'] + $base)->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'boss@clinic.test')->exists());
    }

    public function test_the_user_form_and_list_show_branches(): void
    {
        $this->actingAs($this->admin)->get('/users/create')->assertOk()->assertSee('Works in')->assertSee('North Branch');
        $this->rita->branches()->sync([$this->north->id]);
        $this->actingAs($this->admin)->getJson('/users', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('North Branch');
    }

    public function test_switching_from_a_record_page_returns_to_the_dashboard_not_a_missing_page(): void
    {
        $bill = $this->billIn($this->main);

        $this->actingAs($this->admin)->from("/invoices/{$bill->id}")->post('/branch/switch', ['branch' => $this->north->id])->assertRedirect(route('dashboard'));
        $this->actingAs($this->admin)->from('/invoices')->post('/branch/switch', ['branch' => $this->main->id])->assertRedirect('/invoices');
    }

    public function test_a_report_can_be_run_for_any_branch_or_all_of_them_and_says_which(): void
    {
        $this->billIn($this->north);
        $this->billIn($this->north);
        $this->billIn($this->main);
        $day = '&from='.today()->toDateString().'&to='.today()->toDateString();

        // the top bar says Main, the picker on the report overrides it for that report only
        $this->switchTo($this->admin, $this->main);
        $page = $this->actingAs($this->admin)->get('/reports/invoices?branch=all'.$day)->assertOk();
        $page->assertSee('₹1,500')->assertSee('All branches')->assertSee('name="branch"', false)->assertSee('branch=all', false);

        $this->actingAs($this->admin)->get('/reports/invoices?branch='.$this->north->id.$day)->assertOk()
            ->assertSee('₹1,000')->assertDontSee('₹1,500')->assertSee('Branch: North Branch');
        $this->actingAs($this->admin)->get('/reports/invoices?branch='.$this->main->id.$day)->assertOk()->assertSee('₹500')->assertSee('Branch: Main Branch');

        // no param: the branch chosen in the top bar, with the picker showing it
        $this->actingAs($this->admin)->get('/reports/invoices?'.ltrim($day, '&'))->assertOk()->assertSee('Branch: Main Branch');

        // exports follow the same branch
        $this->actingAs($this->admin)->get('/reports/invoices/excel?branch=all'.$day)->assertOk();
        $this->actingAs($this->admin)->get('/reports/invoices/pdf?branch='.$this->north->id.$day)->assertOk();

        // a stale top-bar choice does not leak into the next request
        $this->actingAs($this->admin)->get('/dashboard')->assertSee('₹500');
    }

    public function test_a_report_cannot_be_run_for_a_branch_the_user_cannot_use(): void
    {
        $ceo = User::factory()->create()->assignRole('admin');
        $this->north->update(['is_active' => false]);

        $this->actingAs($ceo)->get('/reports/invoices?branch='.$this->north->id)->assertForbidden();
        $this->actingAs($ceo)->get('/reports/invoices?branch=999')->assertForbidden();
    }

    public function test_reports_do_not_mention_branches_when_there_is_only_one(): void
    {
        $this->north->delete();

        $this->actingAs($this->admin)->get('/reports/invoices')->assertOk()->assertDontSee('Branch: ')->assertDontSee('name="branch"', false);
    }
}
