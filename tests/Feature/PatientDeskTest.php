<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DashboardService;
use App\Services\PatientPaymentService;
use App\Services\PatientTimeline;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The "one person does everything from the patient page" workflow:
 * register -> bill (+ visit) -> pay -> receive old dues, all without leaving the patient.
 */
class PatientDeskTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private Doctor $rao;

    private Service $svc;

    private User $rita;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $this->rao = Doctor::factory()->create(['name' => 'Dr. Rao']);
        $this->svc = Service::factory()->create(['name' => 'Scaling', 'default_price' => 1000]);
        $this->rita = User::factory()->create(['name' => 'Rita'])->assignRole('receptionist');
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function bill(string $price, int $daysAgo = 0, array $extra = [], ?Patient $p = null): Invoice
    {
        $this->actingAs($this->rita)->post('/invoices', array_merge([
            'patient_id' => ($p ?? $this->patient)->id, 'invoice_date' => today()->subDays($daysAgo)->toDateString(),
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => $price]],
        ], $extra))->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function receive(string $amount, array $over = [], string $role = 'receptionist', ?Patient $p = null): TestResponse
    {
        $user = $role === 'receptionist' ? $this->rita : User::factory()->create()->assignRole($role);

        return $this->actingAs($user)->post('/patients/'.($p ?? $this->patient)->id.'/payments', array_merge([
            'amount' => $amount, 'mode' => 'cash', 'payment_date' => today()->toDateString(),
        ], $over));
    }

    // ─── billing from the patient page ────────────────────────────────────────────

    public function test_billing_can_also_record_todays_visit(): void
    {
        $inv = $this->bill('1000', 0, ['doctor_id' => $this->rao->id, 'create_visit' => 1, 'visit_complaint' => 'Toothache']);

        $visit = Visit::firstOrFail();
        $this->assertSame($this->patient->id, $visit->patient_id);
        $this->assertSame($this->rao->id, $visit->doctor_id);
        $this->assertSame('Toothache', $visit->chief_complaint);
        $this->assertSame('Scaling', $visit->treatment_given);
        $this->assertSame(today()->toDateString(), $visit->visit_date->toDateString());
        $this->assertSame($visit->id, $inv->visit_id);                    // the bill points at the visit it created
        $this->assertNotNull($visit->created_by);
    }

    public function test_visit_gets_a_sensible_default_complaint_and_lists_every_service(): void
    {
        $other = Service::factory()->create(['name' => 'X-ray']);
        $this->actingAs($this->rita)->post('/invoices', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id, 'invoice_date' => today()->toDateString(), 'create_visit' => 1,
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 500], ['service_id' => $other->id, 'quantity' => 1, 'unit_price' => 200], ['service_id' => $this->svc->id, 'quantity' => 2, 'unit_price' => 500]],
        ])->assertSessionHasNoErrors();

        $v = Visit::firstOrFail();
        $this->assertSame('Consultation / treatment', $v->chief_complaint);
        $this->assertSame('Scaling, X-ray', $v->treatment_given);          // each service named once
    }

    public function test_no_visit_is_created_unless_asked_and_never_for_an_existing_visit(): void
    {
        $this->bill('1000', 0, ['doctor_id' => $this->rao->id, 'create_visit' => 0]);
        $this->assertSame(0, Visit::count());

        $this->bill('1000', 0, ['doctor_id' => $this->rao->id]);                         // flag absent = off (old behaviour)
        $this->assertSame(0, Visit::count());

        $existing = Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id]);
        $inv = $this->bill('1000', 0, ['visit_id' => $existing->id, 'doctor_id' => $this->rao->id, 'create_visit' => 1]);
        $this->assertSame(1, Visit::count());                                             // linked to the visit that was already there
        $this->assertSame($existing->id, $inv->visit_id);
    }

    public function test_recording_a_visit_needs_a_doctor_and_creates_nothing_when_it_fails(): void
    {
        $this->actingAs($this->rita)->post('/invoices', [
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(), 'create_visit' => 1,
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 500]],
        ])->assertSessionHasErrors('doctor_id');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Visit::count());
    }

    public function test_visit_and_bill_succeed_or_fail_together(): void
    {
        // an over-payment fails inside the service after the visit would have been created
        $this->actingAs($this->rita)->post('/invoices', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id, 'invoice_date' => today()->toDateString(), 'create_visit' => 1,
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 500]], 'pay_amount' => 900, 'pay_mode' => 'cash',
        ])->assertSessionHasErrors('pay_amount');

        $this->assertSame(0, Visit::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_billing_from_the_patient_page_returns_to_the_patient_with_print_links(): void
    {
        $res = $this->actingAs($this->rita)->from('/patients/'.$this->patient->id)->post('/invoices', [
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(), 'return_to' => 'patient',
            'doctor_id' => $this->rao->id, 'create_visit' => 1, 'pay_amount' => 400, 'pay_mode' => 'upi', 'pay_reference' => 'U1',
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 1000]],
        ]);
        $inv = Invoice::firstOrFail();

        $res->assertRedirect(route('patients.show', $this->patient))
            ->assertSessionHas('bill_id', $inv->id)->assertSessionHas('receipt_id', $inv->payments()->first()->id);

        $this->followRedirects($res)->assertSee('Print bill')->assertSee('Print receipt')->assertSee($inv->invoice_number);
    }

    public function test_billing_from_the_full_page_still_goes_to_the_bill(): void
    {
        $this->actingAs($this->rita)->post('/invoices', [
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertRedirect(route('invoices.show', Invoice::latest('id')->first() ?? 1));
    }

    public function test_a_failed_bill_comes_back_to_the_patient_page_with_the_panel_open(): void
    {
        $res = $this->actingAs($this->rita)->from('/patients/'.$this->patient->id)->post('/invoices', [
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(), 'return_to' => 'patient',
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 1000]], 'discount_type' => 'amount', 'discount_value' => 50,
        ]);

        $res->assertRedirect('/patients/'.$this->patient->id)->assertSessionHasErrors('discount_reason');
        $this->assertSame(0, Invoice::count());
        $this->followRedirects($res)->assertOk()->assertSee('Please fix the following')->assertSee('Please give a reason for the discount.')
            ->assertSee('bootstrap.Offcanvas.getOrCreateInstance(\'#billDrawer\').show()', false);   // the panel re-opens by itself
    }

    // ─── receive payment: oldest bill first ───────────────────────────────────────

    public function test_money_goes_to_the_oldest_bill_first_and_spills_over(): void
    {
        $a = $this->bill('1000', 10);
        $b = $this->bill('2000', 5);
        $c = $this->bill('500', 1);

        $this->receive('1500')->assertSessionHasNoErrors();

        $this->assertSame(InvoiceStatus::Paid, $a->fresh()->status);
        $this->assertSame('0.00', $a->fresh()->balance_amount);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $b->fresh()->status);
        $this->assertSame('500.00', $b->fresh()->paid_amount);
        $this->assertSame('1500.00', $b->fresh()->balance_amount);
        $this->assertSame(InvoiceStatus::Unpaid, $c->fresh()->status);

        $this->receive('2000')->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::Paid, $b->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $c->fresh()->status);
        $this->assertSame('0.00', app(PatientPaymentService::class)->totalDue($this->patient));
    }

    public function test_one_action_makes_one_receipt_per_bill_sharing_a_batch(): void
    {
        $this->bill('1000', 10);
        $this->bill('2000', 5);
        $this->bill('500', 1);
        $this->receive('3200', ['mode' => 'upi', 'reference_no' => 'UPI-77', 'notes' => 'Cleared old dues']);

        $payments = Payment::orderBy('id')->get();
        $this->assertCount(3, $payments);
        $this->assertSame(['1000.00', '2000.00', '200.00'], $payments->pluck('amount')->all());
        $this->assertCount(1, $payments->pluck('batch_ref')->unique());
        $this->assertNotNull($payments[0]->batch_ref);
        $this->assertSame(['RCP-'.now()->year.'-00001', 'RCP-'.now()->year.'-00002', 'RCP-'.now()->year.'-00003'], $payments->pluck('receipt_number')->all());
        $this->assertSame(['upi', 'upi', 'upi'], $payments->pluck('mode')->map->value->all());
        $this->assertSame(['UPI-77', 'UPI-77', 'UPI-77'], $payments->pluck('reference_no')->all());
        $this->assertSame(['0.00', '0.00', '300.00'], $payments->pluck('balance_after')->all());   // each receipt knows what was left on ITS bill
        $this->assertSame($this->rita->id, $payments[0]->created_by);
    }

    public function test_each_action_gets_its_own_batch(): void
    {
        $this->bill('3000');
        $this->receive('1000');
        $this->receive('1000');

        $this->assertCount(2, Payment::pluck('batch_ref')->unique());
    }

    public function test_cannot_receive_more_than_is_due_or_nothing(): void
    {
        $this->bill('1000');

        $this->receive('1000.01')->assertSessionHasErrors('amount');
        $this->receive('0')->assertSessionHasErrors('amount');
        $this->receive('-5')->assertSessionHasErrors('amount');
        $this->receive('10.555')->assertSessionHasErrors('amount');
        $this->receive('abc')->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_non_cash_needs_a_reference_and_date_cannot_be_in_the_future(): void
    {
        $this->bill('1000');

        $this->receive('500', ['mode' => 'card'])->assertSessionHasErrors('reference_no');
        $this->receive('500', ['mode' => 'barter'])->assertSessionHasErrors('mode');
        $this->receive('500', ['payment_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('payment_date');
        $this->receive('500', ['mode' => 'cash', 'reference_no' => 'ignored'])->assertSessionHasNoErrors();
        $this->assertNull(Payment::first()->reference_no);                                  // a cash receipt never keeps a stray reference
    }

    public function test_nothing_to_receive_is_a_clear_error(): void
    {
        $this->receive('100')->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_cancelled_bills_and_bills_on_an_emi_plan_are_left_alone(): void
    {
        $plain = $this->bill('1000', 3);
        $cancelled = $this->bill('900', 2);
        $this->actingAs(User::factory()->create()->assignRole('admin'))->post("/invoices/{$cancelled->id}/cancel", ['reason' => 'Entered by mistake']);
        $onEmi = $this->bill('6000', 1);
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->patient->id, 'invoice_id' => $onEmi->id, 'treatment_name' => 'Crown',
            'total_amount' => 6000, 'down_payment' => 0, 'calc_by' => 'months', 'months' => 3, 'start_date' => today()->toDateString(), 'due_day' => 15])->assertSessionHasNoErrors();

        $svc = app(PatientPaymentService::class);
        $this->assertSame('1000.00', $svc->totalDue($this->patient));                    // only the plain bill is collectable here
        $this->assertSame([$plain->id], $svc->openInvoices($this->patient)->pluck('id')->all());

        $this->receive('1000.01')->assertSessionHasErrors('amount');                    // the EMI bill is NOT silently included
        $this->receive('1000')->assertSessionHasNoErrors();
        $this->assertSame('6000.00', $onEmi->fresh()->balance_amount);                  // still on its plan
        $this->assertSame('0.00', $cancelled->fresh()->paid_amount);
    }

    public function test_other_patients_bills_are_never_touched(): void
    {
        $other = Patient::factory()->create();
        $theirs = $this->bill('800', 2, [], $other);
        $mine = $this->bill('500');

        $this->receive('500')->assertSessionHasNoErrors();
        $this->assertSame('0.00', $mine->fresh()->balance_amount);
        $this->assertSame('800.00', $theirs->fresh()->balance_amount);
        $this->receive('600')->assertSessionHasErrors('amount');                          // only 0 left for this patient
    }

    public function test_the_service_rechecks_what_is_due_under_lock(): void
    {
        $this->bill('1000');
        $svc = app(PatientPaymentService::class);

        $svc->collect($this->patient, ['amount' => '800', 'mode' => 'cash']);

        $this->expectException(ValidationException::class);
        $svc->collect($this->patient, ['amount' => '300', 'mode' => 'cash']);            // only 200 left now
    }

    public function test_receiving_payment_is_audited_for_every_bill_it_touched(): void
    {
        $a = $this->bill('1000', 3);
        $b = $this->bill('1000', 2);
        $this->receive('1500');

        $this->assertSame(2, ActivityLog::where('subject_type', 'payment')->where('event', 'created')->count());
        foreach ([$a, $b] as $inv) {
            $update = ActivityLog::where('subject_type', 'invoice')->where('subject_id', $inv->id)->where('event', 'updated')->latest('id')->first();
            $this->assertSame($this->rita->id, $update->user_id);
            $this->assertSame('unpaid', $update->old_values['status']);
        }
    }

    // ─── combined receipt ─────────────────────────────────────────────────────────

    public function test_combined_receipt_lists_every_bill_and_the_total(): void
    {
        $a = $this->bill('1000', 5);
        $b = $this->bill('2000', 2);
        $res = $this->receive('2500');
        $ref = Payment::first()->batch_ref;

        $res->assertSessionHas('batch_ref', $ref)->assertSessionHas('receipt_count', 2);
        $this->actingAs($this->rita)->get("/receipts/batch/{$ref}")->assertOk()
            ->assertSee('PAYMENT RECEIPT')->assertSee('RCP-'.now()->year.'-00001')->assertSee('RCP-'.now()->year.'-00002')
            ->assertSee($a->invoice_number)->assertSee($b->invoice_number)->assertSee('₹2,500.00')->assertSee('Rupees Two Thousand Five Hundred Only');

        $pdf = $this->actingAs($this->rita)->get("/receipts/batch/{$ref}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_single_bill_batch_just_opens_the_normal_receipt(): void
    {
        $this->bill('1000');
        $this->receive('400');
        $payment = Payment::firstOrFail();

        $this->actingAs($this->rita)->get("/receipts/batch/{$payment->batch_ref}")->assertRedirect(route('payments.receipt', $payment));
        $this->followRedirects($this->actingAs($this->rita)->get("/receipts/batch/{$payment->batch_ref}"))->assertSee($payment->receipt_number);
    }

    public function test_unknown_or_malformed_batch_references_are_404(): void
    {
        $this->actingAs($this->rita)->get('/receipts/batch/'.Str::uuid())->assertNotFound();
        $this->actingAs($this->rita)->get('/receipts/batch/not-a-uuid')->assertNotFound();
    }

    public function test_receipts_are_protected(): void
    {
        $this->bill('1000');
        $this->receive('500');
        $ref = Payment::first()->batch_ref;

        auth()->logout();
        $this->get("/receipts/batch/{$ref}")->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create()->assignRole('doctor'))->get("/receipts/batch/{$ref}")->assertForbidden();   // not their patient
    }

    // ─── who may do what ──────────────────────────────────────────────────────────

    public function test_only_people_who_can_collect_can_receive_payment(): void
    {
        $this->bill('1000');

        $this->receive('100', [], 'doctor')->assertForbidden();
        $this->assertSame(0, Payment::count());

        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);
        $this->actingAs($login)->post("/patients/{$this->patient->id}/payments", ['amount' => 100, 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertForbidden();
    }

    public function test_receive_payment_respects_patient_visibility(): void
    {
        // a user who can collect but whose role is "doctor-scoped" is not a real combination; check the policy directly
        $this->bill('1000');
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->post("/patients/{$this->patient->id}/payments", ['amount' => 100, 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::count());
    }

    // ─── the patient page itself ──────────────────────────────────────────────────

    public function test_patient_page_shows_who_owes_what_and_offers_the_right_buttons(): void
    {
        $this->bill('1000', 5);
        $this->bill('2500', 1);
        $page = $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk();

        $page->assertSee('Balance due')->assertSee('₹3,500.00')->assertSee('Receive payment')->assertSee('New bill')
            ->assertSee('What they owe')->assertSee('Total due')->assertSee('Unpaid bills, oldest first')
            ->assertSee('id="billDrawer"', false)->assertSee('name="return_to" value="patient"', false)
            ->assertSee('name="create_visit"', false)->assertSee('Also record today');
    }

    public function test_when_nothing_is_owed_the_page_says_so_and_hides_receive_payment(): void
    {
        $this->bill('1000', 0, ['pay_amount' => 1000, 'pay_mode' => 'cash']);
        $page = $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk();

        $page->assertSee('All paid')->assertSee('Nothing is owed')->assertDontSee('id="receiveModal"', false);
    }

    public function test_the_bill_panel_warns_about_earlier_dues(): void
    {
        $this->bill('750', 4);
        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSee('still owes')->assertSee('₹750.00')->assertSee('Receive payment first');
    }

    public function test_the_panel_knows_when_a_visit_is_already_recorded_today(): void
    {
        $page = fn () => $this->actingAs($this->rita)->get("/patients/{$this->patient->id}");

        $page()->assertSee('Saves the doctor, date and treatment');
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id]);
        $page()->assertSee('A visit is already recorded for today')->assertSee('Visit · Dr. Rao');   // also shown in the Today strip
    }

    public function test_the_today_strip_summarises_the_day(): void
    {
        $this->bill('1000', 0, ['doctor_id' => $this->rao->id, 'create_visit' => 1, 'pay_amount' => 300, 'pay_mode' => 'cash']);
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id, 'follow_up_date' => today()->toDateString(), 'follow_up_time' => '16:30']);

        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSee('Visit · Dr. Rao')->assertSee('Partially Paid')->assertSee('₹300.00 received')->assertSee('Follow-up 04:30 PM');
    }

    public function test_the_page_is_empty_friendly_for_a_brand_new_patient(): void
    {
        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSee('Nothing recorded yet today')->assertSee('Nothing has happened for this patient yet')->assertSee('All paid');
    }

    public function test_just_registered_banner_and_auto_open(): void
    {
        $res = $this->actingAs($this->rita)->post('/patients', ['name' => 'New Person', 'gender' => 'male', 'phone' => '9811100011', 'after' => 'bill']);
        $patient = Patient::where('phone', '9811100011')->firstOrFail();

        $res->assertRedirect(route('patients.show', ['patient' => $patient, 'bill' => 1]))->assertSessionHas('just_registered');
        $this->followRedirects($res)->assertSee('New Person')->assertSee(str_replace('-', '-', $patient->uhid))
            ->assertSee('bootstrap.Offcanvas.getOrCreateInstance(\'#billDrawer\').show()', false);

        $plain = $this->actingAs($this->rita)->post('/patients', ['name' => 'Second Person', 'gender' => 'female', 'phone' => '9811100022']);
        $second = Patient::where('phone', '9811100022')->firstOrFail();
        $plain->assertRedirect(route('patients.show', $second));
        $this->followRedirects($plain)->assertSee('is registered')->assertSee('Start the bill');
    }

    public function test_a_doctor_sees_the_history_but_no_billing_buttons(): void
    {
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);
        $this->bill('1000');

        $this->actingAs($login)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSee('What they owe')->assertSee('Record visit')->assertSee('Set follow-up')
            ->assertDontSee('id="billDrawer"', false)->assertDontSee('id="receiveModal"', false)
            ->assertDontSee('>New bill<', false)->assertDontSee('Receive payment');
    }

    public function test_dues_panel_lists_emi_plans_and_marks_bills_that_are_on_a_plan(): void
    {
        $inv = $this->bill('6000', 2);
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->patient->id, 'invoice_id' => $inv->id, 'treatment_name' => 'Crown', 'total_amount' => 6000, 'down_payment' => 0,
            'calc_by' => 'months', 'months' => 3, 'start_date' => today()->toDateString(), 'due_day' => 15]);
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->patient->id, 'treatment_name' => 'Braces', 'total_amount' => 30000, 'down_payment' => 0,
            'calc_by' => 'monthly', 'monthly_amount' => 3000, 'start_date' => today()->toDateString(), 'due_day' => 5])->assertSessionHasNoErrors();
        $standalone = EmiPlan::whereNull('invoice_id')->firstOrFail();

        $page = $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk();
        $page->assertSee('on EMI')->assertSee($standalone->plan_number)->assertSee('Collect instalment')->assertSee('₹30,000.00')
            ->assertSee('data-receive-plan="'.$standalone->id.'"', false)
            // EMI is collected from the same Receive payment dialog (a tab per plan); there is no "bills" tab because nothing is collectable directly
            ->assertSee('id="receiveModal"', false)->assertSee('id="rp-plan-'.$standalone->id.'"', false)->assertDontSee('id="rp-bills"', false)
            ->assertSee('action="'.route('emi-plans.collect', $standalone).'"', false)->assertSee('name="return_to" value="patient"', false);

        $this->actingAs($this->rita)->get("/emi-plans/{$standalone->id}?collect=1")->assertOk()
            ->assertSee("new bootstrap.Modal('#collectModal').show()", false);
    }

    // ─── timeline ─────────────────────────────────────────────────────────────────

    public function test_timeline_is_newest_first_and_mixes_every_kind_of_event(): void
    {
        $this->bill('1000', 8, ['pay_amount' => 1000, 'pay_mode' => 'cash']);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id, 'visit_date' => today()->subDays(3)->toDateString(), 'chief_complaint' => 'Gum pain']);
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'follow_up_date' => today()->addDays(4)->toDateString(), 'reason' => 'Review']);
        $this->bill('400', 0);

        $events = app(PatientTimeline::class)->for($this->patient);
        $types = $events->pluck('type')->all();
        $dates = $events->map(fn ($e) => $e['date']->toDateString())->all();

        foreach (['visit', 'bill', 'payment', 'followup'] as $t) {
            $this->assertContains($t, $types);
        }
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);                                               // strictly newest first
        $this->assertSame('followup', $events->first()['type']);                          // the upcoming follow-up leads
        $this->assertSame(today()->subDays(8)->toDateString(), $events->last()['date']->toDateString());

        $this->actingAs($this->rita)->get("/patients/{$this->patient->id}")->assertOk()
            ->assertSeeInOrder(['Follow-up: Review', 'Today', 'Yesterday' === 'x' ? '' : 'Visit with Dr.', 'Payment received'], false);
    }

    public function test_timeline_shows_cancelled_bills_and_missed_follow_ups_honestly(): void
    {
        $inv = $this->bill('500', 2);
        $this->actingAs(User::factory()->create()->assignRole('admin'))->post("/invoices/{$inv->id}/cancel", ['reason' => 'Duplicate entry']);
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'follow_up_date' => today()->subDays(3)->toDateString()]);

        $events = app(PatientTimeline::class)->for($this->patient);
        $bill = $events->firstWhere('type', 'bill');
        $follow = $events->firstWhere('type', 'followup');

        $this->assertSame('Cancelled', $bill['badge']['label']);
        $this->assertStringContainsString('Duplicate entry', $bill['detail']);
        $this->assertSame('Missed', $follow['badge']['label']);                           // past and unactioned reads as Missed even before the nightly job
        $this->assertSame('danger', $follow['tone']);
    }

    public function test_timeline_is_limited_per_source_and_only_this_patients(): void
    {
        $other = Patient::factory()->create();
        $this->bill('100', 1, [], $other);
        foreach (range(1, 5) as $i) {
            $this->bill('10', $i);
        }

        $events = app(PatientTimeline::class)->for($this->patient, perSource: 3);
        $this->assertSame(3, $events->where('type', 'bill')->count());
        $this->assertSame(0, $events->filter(fn ($e) => str_contains($e['title'], Invoice::where('patient_id', $other->id)->value('invoice_number')))->count());
    }

    // ─── search, registration, help ───────────────────────────────────────────────

    public function test_search_results_carry_what_the_front_desk_needs(): void
    {
        $this->bill('1500', 2);
        $this->bill('500', 1);
        $clean = Patient::factory()->create(['name' => 'Asha Clean', 'phone' => '9811100033']);

        $rows = collect($this->actingAs($this->rita)->getJson('/patients/search?q=Asha')->assertOk()->json('results'))->keyBy('name');

        $this->assertEquals(2000, $rows['Asha Verma']['due']);                            // owes money: shown as a red pill in the dropdown
        $this->assertEquals(0, $rows['Asha Clean']['due']);
        $this->assertSame('9876543210', $rows['Asha Verma']['phone']);
        $this->assertSame($this->patient->uhid, $rows['Asha Verma']['uhid']);
        $this->assertMatchesRegularExpression('/\d+ yrs · /', $rows['Asha Verma']['meta']);
        $this->assertStringContainsString('Asha Verma', $rows['Asha Verma']['text']);        // the plain text label is still there
        $this->assertNotNull($clean);
    }

    public function test_search_ignores_cancelled_bills_when_showing_what_is_owed(): void
    {
        $inv = $this->bill('900');
        $this->actingAs(User::factory()->create()->assignRole('admin'))->post("/invoices/{$inv->id}/cancel", ['reason' => 'Wrong patient']);

        $this->assertEquals(0, $this->actingAs($this->rita)->getJson('/patients/search?q=Asha')->json('results.0.due'));
    }

    public function test_the_register_page_accepts_what_was_typed_in_the_search_box(): void
    {
        $this->actingAs($this->rita)->get('/patients/create?q=9811100099')->assertOk()->assertSee('value="9811100099"', false);
        $this->actingAs($this->rita)->get('/patients/create?q=Meena+Joshi')->assertOk()->assertSee('value="Meena Joshi"', false);
        $this->actingAs($this->rita)->get('/patients/create')->assertOk()->assertSee('Only name, gender and mobile number are needed');
        $this->actingAs($this->rita)->get('/patients/create')->assertSee('Register &amp; start bill', false)->assertSee('Register only')->assertSee('or age');
    }

    public function test_optional_registration_details_open_when_editing_or_when_filled(): void
    {
        $this->actingAs($this->rita)->get('/patients/create')->assertOk()->assertDontSee('collapse show', false);
        $p = Patient::factory()->create(['email' => 'a@b.com']);
        $this->actingAs($this->rita)->get("/patients/{$p->id}/edit")->assertOk()->assertSee('collapse show', false)->assertSee('Save changes');
    }

    public function test_help_page_explains_the_workflow_for_everyone(): void
    {
        foreach (['admin', 'receptionist', 'doctor'] as $role) {
            $this->as($role)->get('/help')->assertOk()->assertSee('A new patient walks in')->assertSee('The same patient comes a second time')
                ->assertSee('Receive payment')->assertSee('oldest unpaid bill first');
        }
        auth()->logout();
        $this->get('/help')->assertRedirect(route('login'));
    }

    public function test_menu_uses_plain_words_and_groups_by_job(): void
    {
        $this->actingAs($this->rita)->get('/dashboard')->assertOk()->assertSee('>Bills<', false)->assertSee('>Receipts<', false)->assertSee('Front desk')
            ->assertSee('>New<', false)->assertSee('Register patient')->assertSee('Take attendance')->assertDontSee('>Audit log<', false);
        $this->as('admin')->get('/dashboard')->assertSee('Audit log')->assertSee('Settings');
    }

    public function test_dashboard_front_desk_blocks(): void
    {
        $this->bill('1000', 2);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->rao->id]);
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'follow_up_date' => today()->subDay()->toDateString(), 'status' => 'missed']);

        $d = app(DashboardService::class)->summary();
        $this->assertSame(1, $d['cards']['unpaid_bills']);
        $this->assertSame(1, $d['cards']['missed_followups']);
        $this->assertCount(1, $d['today_visits']);

        $this->actingAs($this->rita)->get('/dashboard')->assertOk()->assertSee('Needs attention')->assertSee("Today's patients")->assertSee('Asha Verma')
            ->assertSee('Bills with a balance')->assertSee('Missed follow-ups')->assertSee('Register patient')->assertSee('Find a patient');
    }
}
