<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DashboardService;
use App\Services\PatientService;
use App\Services\PaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private Service $braces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->patient = Patient::factory()->create();
        $this->braces = Service::factory()->create(['name' => 'Braces', 'default_price' => 30000, 'gst_percent' => 0]);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $this->braces->id, 'quantity' => 1, 'unit_price' => '30000', 'discount_amount' => 0, 'gst_percent' => 0]],
            'discount_type' => 'amount', 'discount_value' => 0,
            'pay_amount' => 0, 'pay_mode' => 'cash',
        ], $over);
    }

    private function createInvoice(array $over = []): Invoice
    {
        $this->as('receptionist')->post('/invoices', $this->payload($over))->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    // --- creation & totals ---------------------------------------------------

    public function test_invoice_with_zero_payment_is_unpaid(): void
    {
        $inv = $this->createInvoice();

        $this->assertSame('INV-'.now()->year.'-00001', $inv->invoice_number);
        $this->assertSame(InvoiceStatus::Unpaid, $inv->status);
        $this->assertSame('30000.00', $inv->grand_total);
        $this->assertSame('30000.00', $inv->balance_amount);
        $this->assertSame(0, $inv->payments()->count());
    }

    public function test_partial_payment_at_billing_time(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '10000']);

        $this->assertSame(InvoiceStatus::PartiallyPaid, $inv->status);
        $this->assertSame('10000.00', $inv->paid_amount);
        $this->assertSame('20000.00', $inv->balance_amount);

        $p = $inv->payments()->firstOrFail();
        $this->assertSame('RCP-'.now()->year.'-00001', $p->receipt_number);
        $this->assertSame('20000.00', $p->balance_after);
    }

    public function test_full_payment_at_billing_time(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '30000']);

        $this->assertSame(InvoiceStatus::Paid, $inv->status);
        $this->assertSame('0.00', $inv->balance_amount);
    }

    public function test_totals_with_discount_and_gst_are_saved(): void
    {
        $inv = $this->createInvoice([
            'items' => [['service_id' => $this->braces->id, 'quantity' => 2, 'unit_price' => '1000', 'discount_amount' => '200', 'gst_percent' => '18']],
            'discount_type' => 'percent', 'discount_value' => '10', 'discount_reason' => 'Loyal patient',
        ]);

        // subtotal 1800, 10% = 180, tax 324 * (1620/1800) = 291.60, grand 1800 - 180 + 291.60
        $this->assertSame('1800.00', $inv->subtotal);
        $this->assertSame('180.00', $inv->discount_amount);
        $this->assertSame('291.60', $inv->tax_amount);
        $this->assertSame('1911.60', $inv->grand_total);
        $this->assertSame('Loyal patient', $inv->discount_reason);
        $this->assertSame('324.00', $inv->items()->first()->tax_amount);
    }

    public function test_invoice_numbers_increment(): void
    {
        $a = $this->createInvoice();
        $b = $this->createInvoice();

        $this->assertSame('INV-'.now()->year.'-00001', $a->invoice_number);
        $this->assertSame('INV-'.now()->year.'-00002', $b->invoice_number);
    }

    // --- validation ------------------------------------------------------------

    public function test_discount_cannot_exceed_subtotal(): void
    {
        $this->as('receptionist')->post('/invoices', $this->payload(['discount_value' => '30000.01', 'discount_reason' => 'x']))
            ->assertSessionHasErrors('discount_value');
        $this->assertSame(0, Invoice::count());
    }

    public function test_discount_requires_reason(): void
    {
        $this->as('receptionist')->post('/invoices', $this->payload(['discount_value' => 500]))
            ->assertSessionHasErrors('discount_reason');
        $this->as('receptionist')->post('/invoices', $this->payload(['discount_value' => 500, 'discount_reason' => 'Staff']))
            ->assertSessionHasNoErrors();
    }

    public function test_item_discount_cannot_exceed_item_amount(): void
    {
        $this->as('receptionist')->post('/invoices', $this->payload([
            'items' => [['service_id' => $this->braces->id, 'quantity' => 1, 'unit_price' => '100', 'discount_amount' => '101']],
        ]))->assertSessionHasErrors('items.0.discount_amount');
    }

    public function test_payment_cannot_exceed_grand_total(): void
    {
        $this->as('receptionist')->post('/invoices', $this->payload(['pay_amount' => '30000.01']))->assertSessionHasErrors('pay_amount');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_reference_required_for_non_cash(): void
    {
        $this->as('receptionist')->post('/invoices', $this->payload(['pay_amount' => 100, 'pay_mode' => 'upi']))->assertSessionHasErrors('pay_reference');
        $this->as('receptionist')->post('/invoices', $this->payload(['pay_amount' => 100, 'pay_mode' => 'upi', 'pay_reference' => 'UPI123']))
            ->assertSessionHasNoErrors();
        $this->assertSame('UPI123', Payment::firstOrFail()->reference_no);
    }

    public function test_payment_mode_is_required_when_paying(): void
    {
        $r = $this->as('receptionist');
        $r->post('/invoices', $this->payload(['pay_amount' => 100, 'pay_mode' => '']))->assertSessionHasErrors('pay_mode');
        $r->post('/invoices', $this->payload(['pay_amount' => 100, 'pay_mode' => null]))->assertSessionHasErrors('pay_mode');
        $r->post('/invoices', $this->payload(['pay_amount' => 0, 'pay_mode' => '']))->assertSessionHasNoErrors();   // paying later needs no mode
        $this->assertSame(1, Invoice::count());
    }

    public function test_needs_items_and_valid_amounts(): void
    {
        $r = $this->as('receptionist');
        $r->post('/invoices', $this->payload(['items' => []]))->assertSessionHasErrors('items');
        $r->post('/invoices', $this->payload(['items' => [['service_id' => $this->braces->id, 'quantity' => 0, 'unit_price' => '0']]]))
            ->assertSessionHasErrors(['items.0.quantity', 'items.0.unit_price']);
        $r->post('/invoices', $this->payload(['items' => [['service_id' => $this->braces->id, 'quantity' => 1, 'unit_price' => '10.555']]]))
            ->assertSessionHasErrors('items.0.unit_price');
    }

    public function test_inactive_service_cannot_be_billed(): void
    {
        $this->braces->update(['is_active' => false]);
        $this->as('receptionist')->post('/invoices', $this->payload())->assertSessionHasErrors('items.0.service_id');
    }

    // --- collect due -----------------------------------------------------------

    public function test_collect_due_in_instalments_until_paid(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '5000']);
        $r = $this->as('receptionist');

        $r->post("/invoices/{$inv->id}/payments", ['amount' => '10000', 'mode' => 'cash', 'payment_date' => today()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $inv->fresh()->status);
        $this->assertSame('15000.00', $inv->fresh()->paid_amount);

        $r->post("/invoices/{$inv->id}/payments", ['amount' => '15000', 'mode' => 'upi', 'reference_no' => 'R1', 'payment_date' => today()->toDateString()]);
        $inv->refresh();
        $this->assertSame(InvoiceStatus::Paid, $inv->status);
        $this->assertSame('0.00', $inv->balance_amount);
        $this->assertSame(3, $inv->payments()->count());
        $this->assertSame(['RCP-'.now()->year.'-00001', 'RCP-'.now()->year.'-00002', 'RCP-'.now()->year.'-00003'], $inv->payments()->orderBy('id')->pluck('receipt_number')->all());
    }

    public function test_overpayment_is_rejected_and_nothing_is_recorded(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '29000']);

        $this->as('receptionist')->post("/invoices/{$inv->id}/payments", ['amount' => '1000.01', 'mode' => 'cash', 'payment_date' => today()->toDateString()])
            ->assertSessionHasErrors('amount');

        $this->assertSame('1000.00', $inv->fresh()->balance_amount);
        $this->assertSame(1, Payment::count());
    }

    public function test_service_rechecks_balance_under_lock(): void
    {
        // Simulates a second desk paying after this desk loaded its (stale) copy of the invoice.
        $inv = $this->createInvoice(['pay_amount' => '1000']);
        $stale = Invoice::find($inv->id);

        $svc = app(PaymentService::class);
        $svc->collectForInvoice($inv, ['amount' => '29000', 'mode' => 'cash']);

        $this->expectException(ValidationException::class);
        $svc->collectForInvoice($stale, ['amount' => '500', 'mode' => 'cash']);
    }

    public function test_cannot_pay_a_cancelled_or_settled_invoice(): void
    {
        $paid = $this->createInvoice(['pay_amount' => '30000']);
        $this->as('receptionist')->post("/invoices/{$paid->id}/payments", ['amount' => '1', 'mode' => 'cash', 'payment_date' => today()->toDateString()])
            ->assertForbidden();

        $inv = $this->createInvoice();
        $this->as('admin')->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake']);
        $this->as('receptionist')->post("/invoices/{$inv->id}/payments", ['amount' => '1', 'mode' => 'cash', 'payment_date' => today()->toDateString()])
            ->assertForbidden();
    }

    // --- cancel ------------------------------------------------------------------

    public function test_only_admin_can_cancel_with_reason(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '2000']);

        $this->as('receptionist')->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake'])->assertForbidden();
        $this->as('admin')->post("/invoices/{$inv->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertNotSame(InvoiceStatus::Cancelled, $inv->fresh()->status);

        $this->as('admin')->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();
        $inv->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $inv->status);
        $this->assertSame('Entered by mistake', $inv->cancel_reason);
        $this->assertSame('0.00', $inv->balance_amount);
        $this->assertSame('2000.00', $inv->paid_amount);   // collected money stays on record
        $this->assertSame(1, $inv->payments()->count());
        $this->assertNotNull($inv->cancelled_by);
    }

    public function test_cancelled_invoice_is_excluded_from_patient_totals(): void
    {
        $this->createInvoice(['pay_amount' => '1000']);
        $b = $this->createInvoice();
        $this->as('admin')->post("/invoices/{$b->id}/cancel", ['reason' => 'Duplicate bill']);

        $f = app(PatientService::class)->financials($this->patient);
        $this->assertSame(['billed' => 30000.0, 'paid' => 1000.0, 'balance' => 29000.0], $f);
    }

    // --- access ------------------------------------------------------------------

    public function test_doctor_cannot_bill_or_collect_but_can_view_own_patients_invoices(): void
    {
        $inv = $this->createInvoice();
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $d = $this->actingAs($login);

        $d->get('/invoices/create')->assertForbidden();
        $d->post('/invoices', $this->payload())->assertForbidden();
        $d->post("/invoices/{$inv->id}/payments", ['amount' => '1', 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertForbidden();
        $d->get('/payments')->assertForbidden();
        $d->get("/invoices/{$inv->id}")->assertForbidden();   // not their patient yet

        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);
        $d->get("/invoices/{$inv->id}")->assertOk()->assertDontSee('Collect due');
    }

    // --- screens & documents -----------------------------------------------------

    public function test_screens_render(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '1000']);
        $payment = $inv->payments()->first();
        $r = $this->as('admin');

        $r->get('/invoices')->assertOk();
        $r->get('/invoices/create')->assertOk()->assertSee('Braces');
        $r->get("/invoices/{$inv->id}")->assertOk()->assertSee($inv->invoice_number)->assertSee('Collect due')->assertSee('₹29,000.00');
        $r->get("/invoices/{$inv->id}/print")->assertOk()->assertSee('INVOICE')->assertSee('Rupees Thirty Thousand Only');
        $r->get("/payments/{$payment->id}/receipt")->assertOk()->assertSee($payment->receipt_number)->assertSee('Rupees One Thousand Only');
        $r->get('/payments')->assertOk();
    }

    public function test_pdfs_are_generated(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '1000']);
        $payment = $inv->payments()->first();
        $r = $this->as('receptionist');

        $a = $r->get("/invoices/{$inv->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $a->headers->get('Content-Type'));
        $this->assertStringContainsString($inv->invoice_number.'.pdf', $a->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $a->getContent());

        $b = $r->get("/payments/{$payment->id}/receipt.pdf")->assertOk();
        $this->assertSame('application/pdf', $b->headers->get('Content-Type'));
    }

    public function test_datatable_feeds_accept_search(): void
    {
        $inv = $this->createInvoice(['pay_amount' => '1000']);
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $r = $this->as('admin');

        $r->getJson('/invoices?search[value]='.$inv->invoice_number.'&status=partially_paid&due_only=1', $ajax)
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
        $r->getJson('/invoices?search[value]='.urlencode($this->patient->name), $ajax)->assertOk()->assertJsonPath('recordsFiltered', 1);
        $r->getJson('/payments?search[value]='.urlencode($this->patient->name).'&mode=cash', $ajax)->assertOk()->assertJsonPath('recordsFiltered', 1);
        $r->getJson('/visits?search[value]=x', $ajax)->assertOk();
    }

    public function test_dashboard_reflects_billing(): void
    {
        $this->createInvoice(['pay_amount' => '4000']);
        Visit::factory()->create(['patient_id' => $this->patient->id]);

        $cards = app(DashboardService::class)->summary();
        $this->assertSame(4000.0, $cards['cards']['today_collection']);
        $this->assertSame(4000.0, $cards['cards']['month_collection']);
        $this->assertSame(26000.0, $cards['cards']['total_outstanding']);
        $this->assertSame(1, $cards['cards']['today_patients']);
        $this->assertSame(4000.0, end($cards['collection_30_days']['data']));
        $this->assertSame(['Cash'], $cards['collection_by_mode']['labels']);

        $this->as('admin')->get('/dashboard')->assertOk()->assertSee('INV-');
    }
}
