<?php

namespace Tests\Feature;

use App\Enums\EmiPlanStatus;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AttendanceService;
use App\Services\EmployeeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

/** Logo, sidebar, doctor-fee and standard-price lines, quick-add service, EMI from the patient page, users <-> employees. */
class DeskRoundTwoTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private User $admin;

    private User $rita;

    private Patient $patient;

    private Doctor $rao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin User'])->assignRole('admin');
        $this->rita = User::factory()->create(['name' => 'Rita', 'phone' => '9811100001'])->assignRole('receptionist');
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $this->rao = Doctor::factory()->create(['name' => 'Dr. Rao', 'consultation_fee' => 500]);
    }

    private function settings(array $over = []): array
    {
        return array_merge(['hospital_name' => 'Smile Clinic', 'invoice_prefix' => 'INV', 'receipt_prefix' => 'RCP', 'patient_prefix' => 'PAT'], $over);
    }

    // ═══ logo: set in Settings, served by the app itself ═══════════════════════════════

    public function test_logo_is_served_without_the_storage_symlink_and_shows_everywhere(): void
    {
        Storage::fake('public');
        $this->assertNull(logo_url());
        $this->get('/brand/logo')->assertNotFound();                                   // nothing uploaded yet

        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('logo.png', 200, 200)]))->assertSessionHasNoErrors();

        $url = logo_url();
        $this->assertStringContainsString('/brand/logo?v=', $url);
        auth()->logout();
        $res = $this->get('/brand/logo')->assertOk();                                  // public: the login page needs it
        $this->assertStringStartsWith('image/', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age', $res->headers->get('Cache-Control'));

        $this->get('/login')->assertOk()->assertSee($url, false);                      // login page
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee($url, false);   // menu
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee($url, false)->assertSee('Shown in the menu, on the login page and on every printed bill');   // preview + where it is
    }

    public function test_logo_changes_automatically_and_can_be_removed(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('a.png')]));
        $first = setting('hospital_logo');
        $v1 = logo_url();

        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('b.png')]));
        $this->assertNotSame($first, setting('hospital_logo'));
        $this->assertNotSame($v1, logo_url());                                         // new ?v= so browsers fetch the new image at once
        Storage::disk('public')->assertMissing($first);

        $second = setting('hospital_logo');
        $this->actingAs($this->admin)->get('/settings')->assertSee('Remove the current picture');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['remove_logo' => 1]))->assertSessionHasNoErrors();
        $this->assertNull(logo_url());
        Storage::disk('public')->assertMissing($second);
        $this->get('/brand/logo')->assertNotFound();
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertDontSee('/brand/logo', false);
    }

    // ═══ login page picture: upload / replace / remove, all in Settings ═══════════════

    public function test_login_picture_can_be_uploaded_replaced_and_removed_in_settings(): void
    {
        Storage::fake('public');
        $this->assertNull(login_image_url());
        $this->get('/brand/login-image')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('has-photo', false);                 // default colour panel

        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertSee('Login page picture')->assertSee('Clinic logo');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['login_image' => UploadedFile::fake()->image('clinic.jpg', 1600, 900)]))->assertSessionHasNoErrors();

        $first = setting('login_image');
        $url = login_image_url();
        $this->assertStringContainsString('/brand/login-image?v=', $url);
        Storage::disk('public')->assertExists($first);
        auth()->logout();
        $this->assertStringStartsWith('image/', $this->get('/brand/login-image')->assertOk()->headers->get('Content-Type'));
        $this->get('/login')->assertOk()->assertSee('has-photo', false)->assertSee($url, false);   // the login page uses it

        $this->actingAs($this->admin)->put('/settings', $this->settings(['login_image' => UploadedFile::fake()->image('new.png', 1600, 900)]))->assertSessionHasNoErrors();
        $this->assertNotSame($first, setting('login_image'));
        $this->assertNotSame($url, login_image_url());                                       // new ?v= so browsers show the new one at once
        Storage::disk('public')->assertMissing($first);                                      // the old file is replaced, not left behind

        $second = setting('login_image');
        $this->actingAs($this->admin)->get('/settings')->assertSee('Remove the current picture');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['remove_login_image' => 1]))->assertSessionHasNoErrors();
        $this->assertNull(login_image_url());
        Storage::disk('public')->assertMissing($second);
        auth()->logout();
        $this->get('/login')->assertOk()->assertDontSee('has-photo', false);
    }

    public function test_logo_and_login_picture_are_independent(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings([
            'hospital_logo' => UploadedFile::fake()->image('logo.png', 100, 100), 'login_image' => UploadedFile::fake()->image('bg.jpg', 1200, 800),
        ]))->assertSessionHasNoErrors();
        $logo = setting('hospital_logo');
        $bg = setting('login_image');
        $this->assertNotSame($logo, $bg);

        $this->actingAs($this->admin)->put('/settings', $this->settings(['remove_login_image' => 1]));          // only the login picture goes
        $this->assertNull(setting('login_image'));
        $this->assertSame($logo, setting('hospital_logo'));
        Storage::disk('public')->assertExists($logo);

        $this->actingAs($this->admin)->put('/settings', $this->settings());                                      // saving other settings keeps both pictures
        $this->assertSame($logo, setting('hospital_logo'));
    }

    public function test_login_picture_validation_and_access(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['login_image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('login_image');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['login_image' => UploadedFile::fake()->image('huge.jpg')->size(4096)]))->assertSessionHasErrors('login_image');
        $this->assertNull(login_image_url());

        $this->actingAs($this->rita)->put('/settings', $this->settings(['login_image' => UploadedFile::fake()->image('bg.jpg')]))->assertForbidden();
        $this->assertNull(login_image_url());
    }

    public function test_uploading_a_new_logo_wins_over_the_remove_box(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('a.png'), 'remove_logo' => 1]));

        $this->assertNotNull(logo_url());
    }

    public function test_bad_logo_files_are_rejected(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]))->assertSessionHasErrors('hospital_logo');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('big.png')->size(2048)]))->assertSessionHasErrors('hospital_logo');
        $this->assertNull(logo_url());
    }

    public function test_printed_documents_carry_the_logo_without_any_symlink(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin)->put('/settings', $this->settings(['hospital_logo' => UploadedFile::fake()->image('logo.png', 120, 120)]));
        $svc = Service::factory()->create();
        $this->actingAs($this->rita)->post('/invoices', ['patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(), 'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 100]]]);
        $inv = Invoice::firstOrFail();

        $this->actingAs($this->rita)->get("/invoices/{$inv->id}/print")->assertOk()->assertSee(logo_url(), false);
        $pdf = $this->actingAs($this->rita)->get("/invoices/{$inv->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_only_admin_changes_the_logo(): void
    {
        $this->actingAs($this->rita)->put('/settings', $this->settings())->assertForbidden();
        $this->actingAs($this->rita)->get('/settings')->assertForbidden();
    }

    // ═══ sidebar ═══════════════════════════════════════════════════════════════════════

    public function test_sidebar_is_a_rail_that_can_be_pinned_open(): void
    {
        $page = $this->actingAs($this->rita)->get('/dashboard')->assertOk();

        $page->assertSee('id="sidebarPin"', false)->assertSee('Pin menu open')->assertSee('class="sidebar"', false)
            ->assertSee("localStorage.getItem('sidebarPinned')", false)->assertSee("classList.add('sidebar-pinned')", false);

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('.sidebar:hover', $css);                       // opens on hover
        $this->assertStringContainsString('html.sidebar-pinned .sidebar', $css);        // stays open when pinned
        $this->assertStringContainsString('.sidebar { width: var(--sidebar-w-collapsed)', $css);   // narrow by default
        $this->assertStringNotContainsString('sidebar-collapsed', $css);
    }

    public function test_the_menu_stays_open_under_the_cursor_after_a_menu_click(): void
    {
        // A new page cannot tell the mouse is still over the menu until it moves, so a click leaves a short-lived flag
        // that keeps the menu open until the mouse goes elsewhere.
        $page = $this->actingAs($this->rita)->get('/payments')->assertOk();
        $page->assertSee("sessionStorage.getItem('sidebarKeepOpen')", false)->assertSee("classList.add('sidebar-keepopen')", false);

        $js = file_get_contents(public_path('js/app.js'));
        $this->assertStringContainsString("sessionStorage.setItem('sidebarKeepOpen'", $js);
        $this->assertStringContainsString("closest('#sidebar')", $js);                  // closes as soon as the mouse is anywhere else
        $this->assertStringContainsString('setTimeout(close, 6000)', $js);              // and never stays open on its own for long

        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('html.sidebar-keepopen .sidebar { width: var(--sidebar-w)', $css);
        $this->assertStringContainsString('html.sidebar-keepopen .sidebar .nav-item-link span', $css);   // labels show while kept open
    }

    // ═══ doctor fee + standard price on bill lines ════════════════════════════════════

    private function bill_(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->rita)->post('/invoices', array_merge(['patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(), 'items' => $items], $extra));
    }

    public function test_doctor_fee_line_is_named_after_the_doctor_and_remembers_the_fee(): void
    {
        $consult = Service::doctorConsultation();
        $svc = Service::factory()->create(['name' => 'Scaling', 'default_price' => 1200]);

        $this->bill_([
            ['service_id' => $consult->id, 'quantity' => 1, 'unit_price' => 500, 'doctor_fee' => 1, 'description' => 'Consultation · Dr. Rao'],
            ['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 1200],
        ], ['doctor_id' => $this->rao->id])->assertSessionHasNoErrors();

        $items = Invoice::firstOrFail()->items()->orderBy('id')->get();
        $this->assertSame('Consultation · Dr. Rao', $items[0]->description);
        $this->assertSame('500.00', $items[0]->list_price);                              // the doctor's fee, from the server
        $this->assertSame('Scaling', $items[1]->description);
        $this->assertSame('1200.00', $items[1]->list_price);                            // the catalogue price
        $this->assertSame('1700.00', Invoice::first()->grand_total);
    }

    public function test_a_lowered_price_keeps_the_standard_price_so_the_reduction_is_visible(): void
    {
        $svc = Service::factory()->create(['name' => 'Filling', 'default_price' => 1500]);
        $this->bill_([['service_id' => $svc->id, 'quantity' => 2, 'unit_price' => 1200]])->assertSessionHasNoErrors();
        $item = InvoiceItem::firstOrFail();

        $this->assertSame('1500.00', $item->list_price);
        $this->assertSame('1200.00', $item->unit_price);
        $this->assertSame('600.00', $item->reduction);                                    // (1500 - 1200) x 2

        $inv = Invoice::firstOrFail();
        $this->actingAs($this->rita)->get("/invoices/{$inv->id}")->assertOk()->assertSee('₹1,500.00')->assertSee('₹1,200.00')->assertSee('Below standard prices')->assertSee('₹600.00');
    }

    public function test_prices_at_or_above_standard_show_no_reduction(): void
    {
        $svc = Service::factory()->create(['default_price' => 1000]);
        $this->bill_([['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 1000], ['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 1300]])->assertSessionHasNoErrors();

        $this->assertSame(['0.00', '0.00'], Invoice::first()->items->map->reduction->all());
        $this->actingAs($this->rita)->get('/invoices/'.Invoice::first()->id)->assertDontSee('Below standard prices');
    }

    public function test_the_server_decides_the_standard_price_not_the_browser(): void
    {
        $svc = Service::factory()->create(['default_price' => 1000]);
        $this->bill_([['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 100, 'list_price' => 100]])->assertSessionHasNoErrors();   // tries to hide the reduction

        $this->assertSame('1000.00', InvoiceItem::first()->list_price);
    }

    public function test_description_is_only_honoured_for_doctor_fee_lines(): void
    {
        $svc = Service::factory()->create(['name' => 'Scaling', 'default_price' => 100]);
        $this->bill_([['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 100, 'description' => 'Anything I like']])->assertSessionHasNoErrors();

        $this->assertSame('Scaling', InvoiceItem::first()->description);
    }

    public function test_a_doctor_fee_line_needs_a_doctor_on_the_bill(): void
    {
        $this->bill_([['service_id' => Service::doctorConsultation()->id, 'quantity' => 1, 'unit_price' => 500, 'doctor_fee' => 1]])->assertSessionHasErrors('doctor_id');
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_consultation_line_is_always_available_and_hidden_from_the_dropdown(): void
    {
        $a = Service::doctorConsultation();
        $b = Service::doctorConsultation();
        $this->assertSame($a->id, $b->id);                                               // one system line, never duplicated

        $a->update(['is_active' => false]);
        $a->delete();
        $restored = Service::doctorConsultation();
        $this->assertSame($a->id, $restored->id);
        $this->assertTrue($restored->is_active);
        $this->assertFalse($restored->trashed());                                         // bills keep working even if someone removed it from the catalogue

        Service::factory()->create(['name' => 'Scaling']);
        $page = $this->actingAs($this->rita)->get('/invoices/create')->assertOk();
        $page->assertSee('consultId: '.$a->id, false)->assertSee('Scaling')
            ->assertSee('"'.$this->rao->id.'":{"name":"Dr. Rao","fee":500', false);        // doctor -> fee map the form uses
        $this->assertStringNotContainsString('"name":"Doctor consultation"', $page->getContent());
    }

    public function test_the_patient_page_bill_panel_gets_the_same_fee_map(): void
    {
        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSee('"'.$this->rao->id.'":{"name":"Dr. Rao","fee":500', false)->assertSee('quickUrl', false)->assertSee('id="quickServiceModal"', false)
            ->assertSee('id="reductionRow"', false)->assertSee('Below standard prices');
    }

    public function test_the_bill_script_ships_the_new_behaviour(): void
    {
        $js = file_get_contents(public_path('js/invoice-form.js'));

        foreach (['syncDoctorFee', 'data-auto', 'dismissedDoctor', 'js-quick-add', 'quickServiceForm', 'hint-down', '__new__', 'reductionRow'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
    }

    // ═══ add a service from the bill ═══════════════════════════════════════════════════

    public function test_anyone_who_can_bill_can_add_a_service_from_the_bill(): void
    {
        $res = $this->actingAs($this->rita)->postJson('/services/quick', ['name' => ' Teeth cleaning ', 'category' => 'procedure', 'default_price' => '800', 'gst_percent' => ''])->assertCreated();

        $res->assertJson(['name' => 'Teeth cleaning', 'group' => 'Procedure', 'price' => 800, 'gst' => 0]);
        $svc = Service::where('name', 'Teeth cleaning')->firstOrFail();
        $this->assertTrue($svc->is_active);
        $this->assertSame($this->rita->id, $svc->created_by);
        $this->assertSame($svc->id, $res->json('id'));

        $this->actingAs($this->rita)->get('/invoices/create')->assertSee('Teeth cleaning');   // available to every later bill
        $this->bill_([['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 800]])->assertSessionHasNoErrors();
    }

    public function test_quick_add_validation(): void
    {
        Service::factory()->create(['name' => 'Scaling', 'category' => 'procedure']);
        $r = $this->actingAs($this->rita);

        $r->postJson('/services/quick', ['name' => '', 'category' => 'procedure', 'default_price' => 100])->assertStatus(422)->assertJsonValidationErrors('name');
        $r->postJson('/services/quick', ['name' => 'Scaling', 'category' => 'procedure', 'default_price' => 100])->assertStatus(422)->assertJsonValidationErrors('name');   // already there
        $r->postJson('/services/quick', ['name' => 'Scaling', 'category' => 'lab', 'default_price' => 100])->assertCreated();                                               // other category is fine
        $r->postJson('/services/quick', ['name' => 'X', 'category' => 'nonsense', 'default_price' => 100])->assertStatus(422)->assertJsonValidationErrors('category');
        $r->postJson('/services/quick', ['name' => 'Y', 'category' => 'lab', 'default_price' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('default_price');
        $r->postJson('/services/quick', ['name' => 'Y', 'category' => 'lab', 'default_price' => '10.555'])->assertStatus(422)->assertJsonValidationErrors('default_price');
        $r->postJson('/services/quick', ['name' => 'Y', 'category' => 'lab', 'default_price' => '-1'])->assertStatus(422)->assertJsonValidationErrors('default_price');
        $r->postJson('/services/quick', ['name' => 'Y', 'category' => 'lab', 'default_price' => 100, 'gst_percent' => 150])->assertStatus(422)->assertJsonValidationErrors('gst_percent');
    }

    public function test_doctors_cannot_add_services_and_the_full_catalogue_stays_admin_only(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('doctor'))->postJson('/services/quick', ['name' => 'X', 'category' => 'lab', 'default_price' => 1])->assertForbidden();
        $this->actingAs($this->rita)->get('/services')->assertForbidden();
        auth()->logout();
        $this->postJson('/services/quick', ['name' => 'X', 'category' => 'lab', 'default_price' => 1])->assertUnauthorized();
    }

    // ═══ EMI: choose a bill, and collect from the patient page ═════════════════════════

    private function bill(string $price, int $daysAgo = 0): Invoice
    {
        $svc = Service::factory()->create(['default_price' => $price]);
        $this->actingAs($this->rita)->post('/invoices', ['patient_id' => $this->patient->id, 'invoice_date' => today()->subDays($daysAgo)->toDateString(), 'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => $price]]])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function plan(array $over = []): EmiPlan
    {
        $this->actingAs($this->rita)->post('/emi-plans', array_merge(['patient_id' => $this->patient->id, 'treatment_name' => 'Braces', 'total_amount' => 30000, 'down_payment' => 0,
            'calc_by' => 'monthly', 'monthly_amount' => 3000, 'start_date' => today()->toDateString(), 'due_day' => 5], $over))->assertSessionHasNoErrors();

        return EmiPlan::latest('id')->firstOrFail();
    }

    public function test_open_bills_lists_only_bills_that_can_go_on_a_plan(): void
    {
        $plain = $this->bill('1000', 3);
        $paid = $this->bill('500');
        $this->actingAs($this->rita)->post("/invoices/{$paid->id}/payments", ['amount' => 500, 'mode' => 'cash', 'payment_date' => today()->toDateString()]);
        $cancelled = $this->bill('700', 2);
        $this->actingAs($this->admin)->post("/invoices/{$cancelled->id}/cancel", ['reason' => 'Entered by mistake']);
        $onPlan = $this->bill('6000', 1);
        $this->plan(['invoice_id' => $onPlan->id, 'total_amount' => 6000, 'calc_by' => 'months', 'months' => 3, 'monthly_amount' => null]);

        $rows = $this->actingAs($this->rita)->getJson("/patients/{$this->patient->id}/open-bills")->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame($plain->id, $rows[0]['id']);
        $this->assertSame($plain->invoice_number, $rows[0]['number']);
        $this->assertEquals(1000, $rows[0]['balance']);
        $this->assertSame('₹1,000.00', $rows[0]['balance_fmt']);
    }

    public function test_open_bills_respects_who_may_see_the_patient(): void
    {
        $this->bill('1000');
        $login = User::factory()->create()->assignRole('doctor');
        Doctor::factory()->create(['user_id' => $login->id]);

        $this->actingAs($login)->getJson("/patients/{$this->patient->id}/open-bills")->assertForbidden();   // not their patient
        auth()->logout();
        $this->getJson("/patients/{$this->patient->id}/open-bills")->assertUnauthorized();
    }

    public function test_the_emi_form_offers_the_picker_and_a_bill_can_be_chosen(): void
    {
        $inv = $this->bill('4000');

        $page = $this->actingAs($this->rita)->get('/emi-plans/create?patient='.$this->patient->id)->assertOk();
        $page->assertSee('Split an existing bill into instalments')->assertSee('id="billPickWrap"', false)->assertSee('name="invoice_id"', false)
            ->assertSee('open-bills', false);

        $plan = $this->plan(['invoice_id' => $inv->id, 'total_amount' => 4000, 'calc_by' => 'months', 'months' => 4, 'monthly_amount' => null]);
        $this->assertSame($inv->id, $plan->invoice_id);
        $this->assertFalse($inv->fresh()->canCollect());                                    // now paid through the plan
    }

    public function test_the_picker_is_replaced_by_the_bill_when_starting_from_a_bill(): void
    {
        $inv = $this->bill('4000');

        $this->actingAs($this->rita)->get('/emi-plans/create?invoice='.$inv->id)->assertOk()->assertDontSee('id="billPickWrap"', false)
            ->assertSee('name="invoice_id" value="'.$inv->id.'"', false);
    }

    public function test_emi_can_be_collected_from_the_patient_page_and_returns_there(): void
    {
        $plan = $this->plan();

        $res = $this->actingAs($this->rita)->from("/patients/{$this->patient->id}")->post("/emi-plans/{$plan->id}/payments", [
            'amount' => 4500, 'mode' => 'upi', 'reference_no' => 'U9', 'payment_date' => today()->toDateString(), 'return_to' => 'patient', '_plan' => $plan->id,
        ]);
        $payment = Payment::firstOrFail();

        $res->assertRedirect(route('patients.show', $this->patient))->assertSessionHas('receipt_id', $payment->id)->assertSessionHas('desk', true);
        $this->assertSame('25500.00', $plan->fresh()->balance_amount);
        $this->assertSame(['3000.00', '1500.00'], $payment->allocations()->orderBy('id')->pluck('amount')->all());   // oldest instalment first, as always
        $this->followRedirects($res)->assertSee('Print receipt')->assertSee('Payment of ₹4,500.00');
    }

    public function test_emi_collected_on_the_plan_page_still_returns_to_the_plan_page(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->rita)->post("/emi-plans/{$plan->id}/payments", ['amount' => 1000, 'mode' => 'cash', 'payment_date' => today()->toDateString()])
            ->assertRedirect(route('emi-plans.show', $plan));
    }

    public function test_a_failed_emi_payment_comes_back_with_that_plans_tab_open(): void
    {
        $plan = $this->plan();
        $res = $this->actingAs($this->rita)->from("/patients/{$this->patient->id}")->post("/emi-plans/{$plan->id}/payments", [
            'amount' => 4000, 'mode' => 'card', 'payment_date' => today()->toDateString(), 'return_to' => 'patient', '_plan' => $plan->id,
        ]);

        $res->assertRedirect("/patients/{$this->patient->id}")->assertSessionHasErrors('reference_no');
        $this->assertSame(0, Payment::count());
        $this->followRedirects($res)->assertOk()->assertSee('Reference number is required for non-cash payments.')
            ->assertSee("bootstrap.Modal.getOrCreateInstance('#receiveModal').show()", false);                  // the dialog reopens by itself
    }

    public function test_the_receive_dialog_has_a_tab_for_bills_and_one_per_plan(): void
    {
        $bill = $this->bill('900', 4);
        $p1 = $this->plan(['treatment_name' => 'Braces']);
        $p2 = $this->plan(['treatment_name' => 'Aligners', 'total_amount' => 6000, 'monthly_amount' => 2000]);
        $onBill = $this->bill('5000', 1);
        $p3 = $this->plan(['invoice_id' => $onBill->id, 'treatment_name' => 'Crown', 'total_amount' => 5000, 'calc_by' => 'months', 'months' => 5, 'monthly_amount' => null]);

        $page = $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk();
        $page->assertSee('id="rp-bills"', false)->assertSee('Bills')->assertSee('₹900.00');                        // bills tab: only the plain bill is collectable
        foreach ([$p1, $p2, $p3] as $plan) {
            $page->assertSee('id="rp-plan-'.$plan->id.'"', false)->assertSee('action="'.route('emi-plans.collect', $plan).'"', false);
        }
        $page->assertSee('EMI · Braces')->assertSee('EMI · Aligners')->assertSee('EMI · Crown')->assertSee('Pays bill '.$onBill->invoice_number);
        $this->assertSame(1, substr_count($page->getContent(), 'id="rp-bills"'));
    }

    public function test_receive_payment_shows_for_emi_only_patients_and_only_to_people_who_can_collect_it(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()->assertSee('Receive payment')->assertSee('data-bs-target="#receiveModal"', false)
            ->assertSee('id="rp-plan-'.$plan->id.'"', false);

        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);
        $this->actingAs($login)->get("/patients/{$this->patient->id}")->assertOk()->assertDontSee('id="receiveModal"', false)->assertDontSee('Receive payment');
    }

    public function test_a_completed_plan_no_longer_appears_in_the_dialog(): void
    {
        $plan = $this->plan(['total_amount' => 3000, 'monthly_amount' => 3000]);
        $this->actingAs($this->rita)->post("/emi-plans/{$plan->id}/payments", ['amount' => 3000, 'mode' => 'cash', 'payment_date' => today()->toDateString()]);

        $this->assertSame(EmiPlanStatus::Completed, $plan->fresh()->status);
        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()->assertDontSee('id="rp-plan-'.$plan->id.'"', false)->assertDontSee('id="receiveModal"', false);
    }

    // ═══ users and employees are the same people ═══════════════════════════════════════

    private function newUser(array $over = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/users', array_merge([
            'name' => 'Meena Joshi', 'email' => 'meena@clinic.test', 'phone' => '9811122233', 'role' => 'receptionist',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'is_active' => 1,
            'branches' => [Branch::main()->id],
        ], $over));
    }

    public function test_creating_a_login_can_also_add_the_person_to_employees(): void
    {
        $this->newUser(['add_as_employee' => 1, 'designation' => 'Front desk', 'joining_date' => '2026-01-05'])->assertSessionHasNoErrors();

        $user = User::where('email', 'meena@clinic.test')->firstOrFail();
        $e = Employee::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Meena Joshi', $e->name);
        $this->assertSame('9811122233', $e->phone);
        $this->assertSame('Front desk', $e->designation);
        $this->assertSame('2026-01-05', $e->joining_date->toDateString());
        $this->assertMatchesRegularExpression('/^EMP-\d{3}$/', $e->employee_code);
        $this->assertTrue($e->is_active);
        $this->assertSame($e->id, $user->employee->id);
        $this->assertSame($user->id, $e->user->id);
    }

    public function test_designation_defaults_to_the_login_role(): void
    {
        $this->newUser(['add_as_employee' => 1, 'role' => 'doctor'])->assertSessionHasNoErrors();

        $this->assertSame('Doctor', Employee::firstOrFail()->designation);
    }

    public function test_a_login_can_be_created_without_a_staff_record(): void
    {
        $this->newUser(['add_as_employee' => 0])->assertSessionHasNoErrors();
        $this->newUser(['email' => 'second@clinic.test'])->assertSessionHasNoErrors();      // flag absent = off

        $this->assertSame(0, Employee::count());
    }

    public function test_a_new_login_appears_on_the_attendance_sheet(): void
    {
        $this->newUser(['add_as_employee' => 1])->assertSessionHasNoErrors();

        $this->assertTrue(app(AttendanceService::class)->sheet(today())->contains('name', 'Meena Joshi'));
        $this->actingAs($this->rita)->get('/attendance')->assertOk()->assertSee('Meena Joshi');
    }

    public function test_editing_a_login_keeps_the_staff_record_in_step_or_adds_one(): void
    {
        $this->newUser(['add_as_employee' => 1])->assertSessionHasNoErrors();
        $user = User::where('email', 'meena@clinic.test')->first();

        $this->actingAs($this->admin)->put("/users/{$user->id}", ['name' => 'Meena J. Patil', 'email' => 'meena@clinic.test', 'phone' => '9899900011', 'role' => 'receptionist', 'is_active' => 1, 'branches' => [Branch::main()->id]])->assertSessionHasNoErrors();
        $e = $user->employee()->first();
        $this->assertSame('Meena J. Patil', $e->name);
        $this->assertSame('9899900011', $e->phone);
        $this->assertSame(1, Employee::count());                                            // updated, not duplicated

        $other = User::factory()->create()->assignRole('receptionist');                      // an existing login with no staff record
        $this->actingAs($this->admin)->put("/users/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'role' => 'receptionist', 'is_active' => 1, 'add_as_employee' => 1, 'branches' => [Branch::main()->id]])->assertSessionHasNoErrors();
        $this->assertNotNull($other->fresh()->employee);
    }

    public function test_the_user_form_explains_and_shows_the_link(): void
    {
        $this->actingAs($this->admin)->get('/users/create')->assertOk()->assertSee('Also add to Employees')->assertSee('id="staffFields"', false);
        $this->newUser(['add_as_employee' => 1]);
        $user = User::where('email', 'meena@clinic.test')->first();
        $this->actingAs($this->admin)->get("/users/{$user->id}/edit")->assertOk()->assertSee('On the staff list as')->assertSee($user->employee->employee_code);
    }

    public function test_one_click_adds_every_login_that_is_not_on_the_list(): void
    {
        User::factory()->create(['name' => 'Unlinked Two'])->assignRole('doctor');
        $this->assertSame(0, Employee::count());

        $page = $this->actingAs($this->admin)->get('/employees')->assertOk();
        $page->assertSee('3 people can log in but are not on this list')->assertSee('Add them to Employees');

        $this->actingAs($this->admin)->post('/employees/sync-logins')->assertRedirect(route('employees.index'))->assertSessionHas('success');
        $this->assertSame(3, Employee::count());
        $this->assertSame(3, Employee::whereNotNull('user_id')->count());
        $this->assertSame(['Admin User', 'Rita', 'Unlinked Two'], Employee::orderBy('name')->pluck('name')->all());
        $this->assertSame('Doctor', Employee::where('name', 'Unlinked Two')->value('designation'));

        $this->actingAs($this->admin)->get('/employees')->assertDontSee('can log in but');
        $this->actingAs($this->admin)->post('/employees/sync-logins')->assertSessionHas('info');   // nothing left to add; never duplicates
        $this->assertSame(3, Employee::count());
    }

    public function test_the_service_is_idempotent_and_never_dates_the_future(): void
    {
        $svc = app(EmployeeService::class);
        $first = $svc->createForUser($this->rita, ['joining_date' => today()->addMonth()->toDateString()]);
        $second = $svc->createForUser($this->rita);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(today()->toDateString(), $first->joining_date->toDateString());      // a future joining date is clamped to today
        $this->assertSame(1, Employee::count());
        $this->assertSame('Receptionist / Billing', $first->designation);
    }

    public function test_employees_can_be_linked_to_a_login_by_hand_once(): void
    {
        $mine = Employee::factory()->create();
        $this->actingAs($this->admin)->get("/employees/{$mine->id}/edit")->assertOk()->assertSee('Login account')->assertSee($this->rita->email);

        $this->actingAs($this->admin)->put("/employees/{$mine->id}", ['employee_code' => $mine->employee_code, 'name' => $mine->name, 'joining_date' => $mine->joining_date->toDateString(), 'is_active' => 1, 'user_id' => $this->rita->id])->assertSessionHasNoErrors();
        $this->assertSame($this->rita->id, $mine->fresh()->user_id);

        $other = Employee::factory()->create();
        $this->actingAs($this->admin)->put("/employees/{$other->id}", ['employee_code' => $other->employee_code, 'name' => $other->name, 'joining_date' => $other->joining_date->toDateString(), 'is_active' => 1, 'user_id' => $this->rita->id])
            ->assertSessionHasErrors('user_id');                                              // one login, one employee
        $this->actingAs($this->admin)->get("/employees/{$other->id}/edit")->assertDontSee($this->rita->email);   // already taken: not offered

        $this->actingAs($this->admin)->put("/employees/{$mine->id}", ['employee_code' => $mine->employee_code, 'name' => $mine->name, 'joining_date' => $mine->joining_date->toDateString(), 'is_active' => 1, 'user_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($mine->fresh()->user_id);                                           // can be unlinked
        $this->actingAs($this->admin)->put("/employees/{$mine->id}", ['employee_code' => $mine->employee_code, 'name' => $mine->name, 'joining_date' => $mine->joining_date->toDateString(), 'is_active' => 1, 'user_id' => 9999])->assertSessionHasErrors('user_id');
    }

    public function test_both_lists_show_the_link(): void
    {
        $this->newUser(['add_as_employee' => 1]);
        Employee::factory()->create(['name' => 'No Login Person', 'employee_code' => 'EMP-777']);
        $this->actingAs($this->admin);

        $emp = collect($this->dt('/employees', ['employee_code', 'name', 'designation', 'phone', 'login', 'joining_date', 'is_active', 'actions'], '', [], ['login', 'joining_date', 'is_active', 'actions'])->assertOk()->json('data'))->keyBy(fn ($r) => strip_tags($r['name']));
        $this->assertStringContainsString('meena@clinic.test', $emp['Meena Joshi']['login']);
        $this->assertStringContainsString('Receptionist', $emp['Meena Joshi']['login']);
        $this->assertStringContainsString('No login', $emp['No Login Person']['login']);

        $users = collect($this->dt('/users', ['name', 'email', 'phone', 'role', 'employee', 'is_active', 'actions'], '', [], ['role', 'employee', 'is_active', 'actions'])->assertOk()->json('data'))->keyBy('name');
        $this->assertStringContainsString(Employee::where('name', 'Meena Joshi')->value('employee_code'), $users['Meena Joshi']['employee']);
        $this->assertStringContainsString('Not on staff list', $users['Rita']['employee']);
    }

    public function test_only_admin_manages_the_link(): void
    {
        $this->actingAs($this->rita)->post('/employees/sync-logins')->assertForbidden();
        $this->actingAs($this->rita)->get('/employees')->assertForbidden();
        $this->actingAs($this->rita)->post('/users', ['name' => 'X', 'email' => 'x@y.com', 'role' => 'doctor', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'add_as_employee' => 1])->assertForbidden();
        $this->assertSame(0, Employee::count());
    }

    public function test_deleting_a_login_keeps_the_employee_and_their_history(): void
    {
        $this->newUser(['add_as_employee' => 1]);
        $user = User::where('email', 'meena@clinic.test')->first();
        $e = $user->employee;
        Attendance::create(['employee_id' => $e->id, 'attendance_date' => today()->toDateString(), 'status' => 'present']);

        $user->forceDelete();

        $this->assertNull($e->fresh()->user_id);                                              // nullOnDelete: history stays, the link is cleared
        $this->assertSame(1, Attendance::count());
    }
}
