<?php

namespace Tests\Feature;

use App\Enums\EmiPlanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\InvoiceStatus;
use App\Models\Doctor;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\DashboardService;
use App\Services\EmiPaymentService;
use App\Services\PatientService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmiTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->patient = Patient::factory()->create(['phone' => '9876543210', 'name' => 'Asha Verma']);
    }

    private function as(string $role): static
    {
        return $this->actingAs(User::factory()->create()->assignRole($role));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'patient_id' => $this->patient->id,
            'treatment_name' => 'Braces',
            'total_amount' => '30000',
            'down_payment' => 0,
            'calc_by' => 'monthly',
            'monthly_amount' => '3000',
            'start_date' => today()->toDateString(),
            'due_day' => 15,
        ], $over);
    }

    private function plan(array $over = [], string $role = 'receptionist'): EmiPlan
    {
        $this->as($role)->post('/emi-plans', $this->payload($over))->assertSessionHasNoErrors();

        return EmiPlan::latest('id')->firstOrFail();
    }

    private function pay(EmiPlan $plan, string $amount, array $over = []): TestResponse
    {
        return $this->as('receptionist')->post("/emi-plans/{$plan->id}/payments", array_merge([
            'amount' => $amount, 'mode' => 'cash', 'payment_date' => today()->toDateString(),
        ], $over));
    }

    /** @return list<string> */
    private function paidAmounts(EmiPlan $plan): array
    {
        return $plan->installments()->get()->map(fn ($i) => $i->paid_amount)->all();
    }

    // --- creating plans ----------------------------------------------------------

    public function test_braces_plan_generates_ten_instalments(): void
    {
        $plan = $this->plan();

        $this->assertSame('EMI-'.now()->year.'-00001', $plan->plan_number);
        $this->assertSame(EmiPlanStatus::Active, $plan->status);
        $this->assertSame(10, $plan->months);
        $this->assertSame('30000.00', $plan->balance_amount);
        $this->assertSame('30000.00', $plan->financed_amount);
        $this->assertSame(10, $plan->installments()->count());
        $this->assertSame('3000.00', $plan->installments()->first()->amount);
        $this->assertSame($plan->installments()->get()->last()->due_date->toDateString(), $plan->end_date->toDateString());
        $this->assertSame(0, Payment::count());
    }

    public function test_plan_by_months_and_down_payment(): void
    {
        $plan = $this->plan(['down_payment' => '6000', 'calc_by' => 'months', 'months' => 8, 'monthly_amount' => null, 'pay_mode' => 'upi', 'pay_reference' => 'UPI42']);

        $this->assertSame('24000.00', $plan->financed_amount);
        $this->assertSame('24000.00', $plan->balance_amount);
        $this->assertSame('0.00', $plan->paid_amount);
        $this->assertSame(8, $plan->installments()->count());
        $this->assertSame('3000.00', $plan->installments()->first()->amount);

        $down = Payment::firstOrFail();
        $this->assertSame('6000.00', $down->amount);
        $this->assertSame($plan->id, $down->emi_plan_id);
        $this->assertSame('UPI42', $down->reference_no);
        $this->assertSame('Down payment', $down->notes);
        $this->assertSame('RCP-'.now()->year.'-00001', $down->receipt_number);
        $this->assertSame(0, $down->allocations()->count());
    }

    public function test_validation_rules(): void
    {
        $r = $this->as('receptionist');
        $bad = fn (array $over, $errors) => $r->post('/emi-plans', $this->payload($over))->assertSessionHasErrors($errors);

        $bad(['down_payment' => '30000'], 'down_payment');                       // must be < total
        $bad(['down_payment' => '35000'], 'down_payment');
        $bad(['total_amount' => '0'], 'total_amount');
        $bad(['total_amount' => '100.123'], 'total_amount');
        $bad(['calc_by' => 'months', 'months' => 0, 'monthly_amount' => null], 'months');
        $bad(['calc_by' => 'months', 'months' => 61, 'monthly_amount' => null], 'months');
        $bad(['calc_by' => 'months', 'months' => null, 'monthly_amount' => null], 'months');
        $bad(['monthly_amount' => '0'], 'monthly_amount');
        $bad(['monthly_amount' => null], 'monthly_amount');
        $bad(['monthly_amount' => '100'], 'monthly_amount');                       // 300 instalments
        $bad(['calc_by' => null], 'calc_by');
        $bad(['due_day' => 0], 'due_day');
        $bad(['due_day' => 32], 'due_day');
        $bad(['treatment_name' => ''], 'treatment_name');
        $bad(['patient_id' => 9999], 'patient_id');
        $bad(['down_payment' => '1000', 'pay_mode' => 'upi'], 'pay_reference');
        $bad(['down_payment' => '1000', 'pay_mode' => ''], 'pay_mode');
        $bad(['down_payment' => '1000'], 'pay_mode');                              // mode missing altogether
        $bad(['down_payment' => '1000', 'pay_mode' => 'barter'], 'pay_mode');

        $this->assertSame(0, EmiPlan::count());
    }

    public function test_start_date_in_the_past_only_for_admin(): void
    {
        $past = today()->subMonths(2)->toDateString();

        $this->as('receptionist')->post('/emi-plans', $this->payload(['start_date' => $past]))->assertSessionHasErrors('start_date');
        $this->assertSame(0, EmiPlan::count());

        $this->as('admin')->post('/emi-plans', $this->payload(['start_date' => $past]))->assertSessionHasNoErrors();
        $this->assertSame(1, EmiPlan::count());
    }

    public function test_preview_returns_the_schedule_without_saving(): void
    {
        $r = $this->as('receptionist');

        $r->postJson('/emi-plans/preview', ['amount' => '30000', 'calc_by' => 'monthly', 'monthly_amount' => '3000', 'start_date' => '2026-10-15', 'due_day' => 5])
            ->assertOk()->assertJsonPath('months', 10)->assertJsonPath('monthly_amount_fmt', '₹3,000.00')
            ->assertJsonPath('first_due_date', '05-11-2026')->assertJsonPath('end_date', '05-08-2027')
            ->assertJsonCount(10, 'installments')->assertJsonPath('installments.0.amount_fmt', '₹3,000.00');

        $r->postJson('/emi-plans/preview', ['amount' => '30000', 'calc_by' => 'monthly', 'monthly_amount' => '100', 'start_date' => '2026-10-15', 'due_day' => 5])
            ->assertStatus(422);
        $r->postJson('/emi-plans/preview', ['amount' => '30000', 'calc_by' => 'months', 'months' => 99, 'start_date' => '2026-10-15', 'due_day' => 5])
            ->assertStatus(422);

        $this->assertSame(0, EmiPlan::count());
    }

    // --- flexible payment allocation ----------------------------------------------

    public function test_less_than_an_instalment_is_a_part_payment(): void
    {
        $plan = $this->plan(['start_date' => today()->addDay()->toDateString()]);
        $this->pay($plan, '1000')->assertSessionHasNoErrors();

        $first = $plan->installments()->first();
        $this->assertSame('1000.00', $first->paid_amount);
        $this->assertSame(InstallmentStatus::PartiallyPaid, $first->effective_status);
        $this->assertSame('29000.00', $plan->fresh()->balance_amount);
        $this->assertSame('1000.00', $plan->fresh()->paid_amount);
        $this->assertSame(EmiPlanStatus::Active, $plan->fresh()->status);
    }

    public function test_exact_instalment_marks_it_paid(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '3000');

        $this->assertSame(InstallmentStatus::Paid, $plan->installments()->first()->effective_status);
        $this->assertSame('27000.00', $plan->fresh()->balance_amount);
        $this->assertNotNull($plan->installments()->first()->paid_on);
    }

    public function test_more_than_an_instalment_carries_into_the_next(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '4500');

        $this->assertSame(['3000.00', '1500.00'], array_slice($this->paidAmounts($plan), 0, 2));
        $this->assertSame('0.00', $plan->installments()->get()[2]->paid_amount);

        $payment = Payment::firstOrFail();
        $this->assertSame(['3000.00', '1500.00'], $payment->allocations()->orderBy('id')->pluck('amount')->all());
        $this->assertSame('25500.00', $payment->balance_after);
    }

    public function test_large_payment_spans_several_instalments(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '10000');

        $this->assertSame(['3000.00', '3000.00', '3000.00', '1000.00', '0.00'], array_slice($this->paidAmounts($plan), 0, 5));
        $this->assertSame(4, Payment::firstOrFail()->allocations()->count());
        $this->assertSame('20000.00', $plan->fresh()->balance_amount);
    }

    public function test_money_goes_to_the_oldest_unpaid_instalment_first(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '1000');
        $this->pay($plan, '3000');

        $this->assertSame(['3000.00', '1000.00', '0.00'], array_slice($this->paidAmounts($plan), 0, 3));
    }

    public function test_each_emi_payment_has_its_own_receipt(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '1000');
        $this->pay($plan, '2000', ['mode' => 'upi', 'reference_no' => 'R9']);

        $this->assertSame(['RCP-'.now()->year.'-00001', 'RCP-'.now()->year.'-00002'], Payment::orderBy('id')->pluck('receipt_number')->all());
    }

    public function test_overpayment_beyond_the_plan_balance_is_rejected(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '29000');

        $this->pay($plan, '1000.01')->assertSessionHasErrors('amount');
        $this->assertSame('1000.00', $plan->fresh()->balance_amount);
        $this->assertSame(1, Payment::count());
    }

    public function test_non_cash_payment_needs_a_reference(): void
    {
        $plan = $this->plan();

        $this->pay($plan, '500', ['mode' => 'card'])->assertSessionHasErrors('reference_no');
        $this->pay($plan, '0')->assertSessionHasErrors('amount');
        $this->pay($plan, '10.555')->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());
    }

    public function test_early_settlement_completes_the_plan(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '3000');
        $this->pay($plan, '27000');   // everything that is left

        $plan->refresh();
        $this->assertSame(EmiPlanStatus::Completed, $plan->status);
        $this->assertSame('0.00', $plan->balance_amount);
        $this->assertSame('30000.00', $plan->paid_amount);
        $this->assertSame(0, $plan->installments()->unpaid()->count());

        $this->pay($plan, '1')->assertForbidden();
    }

    public function test_balance_is_rechecked_under_lock(): void
    {
        $plan = $this->plan();
        $stale = EmiPlan::find($plan->id);
        $svc = app(EmiPaymentService::class);

        $svc->collect($plan, ['amount' => '29000', 'mode' => 'cash']);

        $this->expectException(ValidationException::class);
        $svc->collect($stale, ['amount' => '2000', 'mode' => 'cash']);   // only 1000 left
    }

    public function test_plan_balance_always_equals_the_sum_of_instalment_balances(): void
    {
        $plan = $this->plan(['calc_by' => 'months', 'months' => 7, 'monthly_amount' => null, 'total_amount' => '10000.50']);
        foreach (['1234.56', '0.01', '3333.33', '999.99'] as $amount) {
            $this->pay($plan, $amount)->assertSessionHasNoErrors();
        }

        $sum = $plan->installments()->get()->sum(fn ($i) => to_paise($i->balance));
        $this->assertSame(to_paise($plan->fresh()->balance_amount), (int) $sum);
        $this->assertSame(to_paise('10000.50') - to_paise('5567.89'), (int) $sum);
    }

    // --- overdue & dues ----------------------------------------------------------

    private function overduePlan(): EmiPlan
    {
        return $this->plan(['start_date' => today()->subMonths(3)->startOfMonth()->toDateString(), 'due_day' => 15], 'admin');
    }

    public function test_overdue_status_and_daily_command(): void
    {
        $plan = $this->overduePlan();
        $overdue = $plan->installments()->get()->filter(fn ($i) => $i->due_date->lt(today()));
        $this->assertGreaterThanOrEqual(3, $overdue->count());
        $this->assertSame(InstallmentStatus::Overdue, $overdue->first()->effective_status);

        $this->artisan('emi:mark-overdue')->assertSuccessful();

        $this->assertSame($overdue->count(), EmiInstallment::where('status', 'overdue')->count());
        $this->assertSame($overdue->count(), EmiInstallment::overdue()->count());
        $this->artisan('emi:mark-overdue')->expectsOutputToContain('0 instalment(s)')->assertSuccessful();   // idempotent
    }

    public function test_paying_an_overdue_instalment_clears_its_overdue_state(): void
    {
        $plan = $this->overduePlan();
        $before = EmiInstallment::overdue()->count();

        $this->pay($plan, '3000');

        $this->assertSame($before - 1, EmiInstallment::overdue()->count());
        $this->assertSame(InstallmentStatus::Paid, $plan->installments()->first()->status);
    }

    public function test_overdue_amount_and_behind_flag(): void
    {
        $plan = $this->overduePlan()->load('installments');

        $this->assertTrue($plan->isBehind());
        $this->assertGreaterThanOrEqual(900000, to_paise($plan->overdueAmount()));
        $this->assertFalse($this->plan()->isBehind());
    }

    public function test_dues_lists_with_whatsapp_links(): void
    {
        $this->overduePlan();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $r = $this->as('receptionist');

        $res = $r->getJson('/emi-dues?type=overdue', $ajax)->assertOk();
        $this->assertGreaterThanOrEqual(3, $res->json('recordsTotal'));
        $row = $res->json('data.0');
        $this->assertStringContainsString('https://wa.me/919876543210?text=', $row['actions']);
        $this->assertStringContainsString('Asha%20Verma', $row['actions']);
        $this->assertStringContainsString('overdue', $row['days']);

        $r->getJson('/emi-dues?type=overdue&search[value]=Asha', $ajax)->assertOk();
        $r->getJson('/emi-dues?type=overdue&search[value]=Nobody', $ajax)->assertOk()->assertJsonPath('recordsFiltered', 0);

        // Upcoming = next 7 days only
        $this->plan(['start_date' => today()->toDateString(), 'due_day' => today()->addDays(3)->day]);
        $up = $r->getJson('/emi-dues?type=upcoming', $ajax)->assertOk();
        $this->assertGreaterThanOrEqual(1, $up->json('recordsTotal'));
    }

    public function test_cancelled_plans_drop_out_of_dues(): void
    {
        $plan = $this->overduePlan();
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Patient left treatment']);

        $this->assertSame(0, EmiInstallment::overdue()->count());
    }

    // --- reschedule ----------------------------------------------------------------

    public function test_reschedule_is_admin_only_and_needs_a_reason(): void
    {
        $plan = $this->plan();
        $data = ['calc_by' => 'months', 'months' => 5, 'start_date' => today()->toDateString(), 'due_day' => 10, 'reason' => 'Patient asked'];

        $this->as('receptionist')->post("/emi-plans/{$plan->id}/reschedule", $data)->assertForbidden();
        $this->as('admin')->post("/emi-plans/{$plan->id}/reschedule", ['reason' => ''] + $data)->assertSessionHasErrors('reason');
        $this->assertSame(0, $plan->reschedules()->count());
    }

    public function test_reschedule_keeps_history_and_the_balance(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '10000');   // 3 paid, 4th part-paid (1000)

        $this->as('admin')->post("/emi-plans/{$plan->id}/reschedule", [
            'calc_by' => 'months', 'months' => 4, 'start_date' => today()->addMonth()->toDateString(), 'due_day' => 10, 'reason' => 'Longer period needed',
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame('20000.00', $plan->balance_amount);            // balance untouched
        $this->assertSame('10000.00', $plan->paid_amount);

        $installments = $plan->installments()->get();
        $this->assertSame([1, 2, 3, 4, 11, 12, 13, 14], $installments->pluck('installment_no')->all());
        $this->assertSame('1000.00', $installments[3]->amount);          // part-paid one closed at what was paid
        $this->assertSame(InstallmentStatus::Paid, $installments[3]->status);
        $this->assertSame(['5000.00', '5000.00', '5000.00', '5000.00'], $installments->slice(4)->pluck('amount')->values()->all());
        $this->assertSame(8, $plan->months);
        $this->assertSame('5000.00', $plan->monthly_amount);
        $this->assertSame($installments->last()->due_date->toDateString(), $plan->end_date->toDateString());
        $this->assertSame(20000, (int) $installments->sum(fn ($i) => to_paise($i->balance)) / 100);

        $history = $plan->reschedules()->firstOrFail();
        $this->assertSame('Longer period needed', $history->reason);
        $this->assertSame('20000.00', $history->balance_at_time);
        $this->assertCount(10, $history->old_schedule);                  // the whole old schedule is preserved
        $this->assertSame('3000.00', $history->old_schedule[9]['amount']);

        // payments still allocate correctly on the new schedule
        $this->pay($plan, '5000');
        $this->assertSame('5000.00', $plan->installments()->get()[4]->paid_amount);
    }

    public function test_reschedule_by_monthly_amount_and_validation(): void
    {
        $plan = $this->plan();
        $admin = $this->as('admin');

        $admin->post("/emi-plans/{$plan->id}/reschedule", ['calc_by' => 'monthly', 'monthly_amount' => '100', 'start_date' => today()->toDateString(), 'due_day' => 5, 'reason' => 'Too small'])
            ->assertSessionHasErrors('months');   // would need 300 instalments
        $this->assertSame(10, $plan->installments()->count());

        $admin->post("/emi-plans/{$plan->id}/reschedule", ['calc_by' => 'monthly', 'monthly_amount' => '10000', 'start_date' => today()->toDateString(), 'due_day' => 5, 'reason' => 'Faster'])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, $plan->installments()->count());
    }

    public function test_completed_or_cancelled_plans_cannot_be_rescheduled(): void
    {
        $plan = $this->plan();
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Treatment stopped']);

        $this->as('admin')->post("/emi-plans/{$plan->id}/reschedule", ['calc_by' => 'months', 'months' => 3, 'start_date' => today()->toDateString(), 'due_day' => 5, 'reason' => 'Try again'])
            ->assertForbidden();
    }

    // --- cancel / default ----------------------------------------------------------

    public function test_cancel_needs_admin_and_a_reason_and_blocks_payments(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '3000');

        $this->as('receptionist')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Patient left'])->assertForbidden();
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Patient left'])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(EmiPlanStatus::Cancelled, $plan->status);
        $this->assertSame('Patient left', $plan->cancel_reason);
        $this->assertNotNull($plan->cancelled_by);
        $this->assertSame(1, Payment::count());                            // received money stays on record
        $this->pay($plan, '100')->assertForbidden();
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Again please'])->assertForbidden();
    }

    public function test_defaulted_plan_still_accepts_payment_and_can_complete(): void
    {
        $plan = $this->plan(['total_amount' => '6000', 'monthly_amount' => '3000']);

        $this->as('receptionist')->post("/emi-plans/{$plan->id}/default")->assertForbidden();
        $this->as('admin')->post("/emi-plans/{$plan->id}/default")->assertSessionHasNoErrors();
        $this->assertSame(EmiPlanStatus::Defaulted, $plan->fresh()->status);

        $this->pay($plan, '3000')->assertSessionHasNoErrors();
        $this->assertSame(EmiPlanStatus::Defaulted, $plan->fresh()->status);

        $this->as('admin')->post("/emi-plans/{$plan->id}/reactivate");
        $this->assertSame(EmiPlanStatus::Active, $plan->fresh()->status);

        $this->pay($plan, '3000');
        $this->assertSame(EmiPlanStatus::Completed, $plan->fresh()->status);
    }

    // --- plans tied to invoices ----------------------------------------------------

    private function invoice(string $total = '30000', string $paid = '0'): Invoice
    {
        $svc = Service::factory()->create(['default_price' => $total]);
        $this->as('receptionist')->post('/invoices', [
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => $total]],
            'pay_amount' => $paid, 'pay_mode' => 'cash',
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_plan_from_invoice_settles_the_invoice_as_it_is_paid(): void
    {
        $inv = $this->invoice();
        $plan = $this->plan(['invoice_id' => $inv->id, 'down_payment' => '3000', 'pay_mode' => 'cash']);

        $inv->refresh();
        $this->assertSame($inv->id, $plan->invoice_id);
        $this->assertSame('3000.00', $inv->paid_amount);                     // down payment hit the invoice
        $this->assertSame(InvoiceStatus::PartiallyPaid, $inv->status);
        $this->assertSame('27000.00', $plan->financed_amount);

        $this->pay($plan, '7000');
        $this->assertSame('10000.00', $inv->fresh()->paid_amount);
        $this->assertSame('20000.00', $inv->fresh()->balance_amount);

        $this->pay($plan, '20000');
        $this->assertSame(InvoiceStatus::Paid, $inv->fresh()->status);
        $this->assertSame(EmiPlanStatus::Completed, $plan->fresh()->status);
    }

    public function test_invoice_rules_while_a_plan_is_open(): void
    {
        $inv = $this->invoice();
        $this->plan(['invoice_id' => $inv->id]);
        $inv->refresh();

        $this->assertFalse($inv->canCollect());
        $this->as('receptionist')->post("/invoices/{$inv->id}/payments", ['amount' => '100', 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertForbidden();
        $this->as('admin')->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake'])->assertForbidden();
        $this->as('admin')->get("/invoices/{$inv->id}")->assertOk()->assertSee('EMI-'.now()->year)->assertDontSee('Collect due');

        $this->as('receptionist')->post('/emi-plans', $this->payload(['invoice_id' => $inv->id]))->assertSessionHasErrors('invoice_id');   // one open plan per invoice
    }

    public function test_plan_total_cannot_exceed_the_invoice_balance(): void
    {
        $inv = $this->invoice('30000', '10000');

        $this->as('receptionist')->post('/emi-plans', $this->payload(['invoice_id' => $inv->id, 'total_amount' => '20000.01']))->assertSessionHasErrors('total_amount');
        $this->as('receptionist')->post('/emi-plans', $this->payload(['invoice_id' => $inv->id, 'total_amount' => '20000']))->assertSessionHasNoErrors();
    }

    public function test_cancelled_invoice_cannot_get_a_plan_but_a_cancelled_plan_frees_the_invoice(): void
    {
        $cancelled = $this->invoice();
        $this->as('admin')->post("/invoices/{$cancelled->id}/cancel", ['reason' => 'Wrong patient billed']);
        $this->as('receptionist')->post('/emi-plans', $this->payload(['invoice_id' => $cancelled->id]))->assertSessionHasErrors('invoice_id');

        $inv = $this->invoice();
        $plan = $this->plan(['invoice_id' => $inv->id]);
        $this->as('admin')->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Patient prefers to pay at once']);
        $this->assertTrue($inv->fresh()->canCollect());
    }

    // --- totals, dashboard, screens ------------------------------------------------

    public function test_patient_totals_include_standalone_plans_without_double_counting(): void
    {
        $svc = app(PatientService::class);

        $plan = $this->plan(['down_payment' => '6000', 'calc_by' => 'months', 'months' => 8, 'monthly_amount' => null, 'pay_mode' => 'cash']);
        $this->pay($plan, '3000');
        $this->assertSame(['billed' => 30000.0, 'paid' => 9000.0, 'balance' => 21000.0], $svc->financials($this->patient));

        $inv = $this->invoice('10000');
        $this->plan(['invoice_id' => $inv->id, 'total_amount' => '10000', 'monthly_amount' => '5000']);
        $this->assertSame(['billed' => 40000.0, 'paid' => 9000.0, 'balance' => 31000.0], $svc->financials($this->patient));   // invoice counted once
    }

    public function test_dashboard_shows_overdue_emis_and_outstanding(): void
    {
        $this->overduePlan();
        $d = app(DashboardService::class)->summary();

        $this->assertGreaterThanOrEqual(3, $d['cards']['overdue_emis']);
        $this->assertSame(30000.0, $d['cards']['total_outstanding']);
        $this->assertLessThanOrEqual(10, $d['overdue_installments']->count());
        $this->as('admin')->get('/dashboard')->assertOk()->assertSee('Asha Verma')->assertSee('wa.me/919876543210', false);
    }

    public function test_screens_render(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '4500');
        $r = $this->as('admin');

        $r->get('/emi-plans')->assertOk();
        $r->get('/emi-dues')->assertOk();
        $r->get('/emi-plans/create')->assertOk();
        $r->get('/emi-plans/create?patient='.$this->patient->id)->assertOk()->assertSee('Asha Verma');
        $r->get('/emi-plans/create?visit='.Visit::factory()->create(['patient_id' => $this->patient->id])->id)->assertOk();
        $r->get("/emi-plans/{$plan->id}")->assertOk()->assertSee($plan->plan_number)->assertSee('Collect payment')
            ->assertSee('Reschedule')->assertSee('₹25,500.00')->assertSee('Settle in full');
        $r->get("/patients/{$this->patient->id}")->assertOk()->assertSee($plan->plan_number);
        $r->getJson('/emi-plans?search[value]=Braces&status=active', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.next_due_fmt', format_date($plan->installments()->unpaid()->first()->due_date));
        $r->getJson('/payments?search[value]=Asha', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('data.0.against', $plan->plan_number);
    }

    public function test_receipt_shows_the_plan_and_instalments_and_pdf_works(): void
    {
        $plan = $this->plan();
        $this->pay($plan, '4500');
        $payment = Payment::firstOrFail();

        $this->as('receptionist')->get("/payments/{$payment->id}/receipt")->assertOk()
            ->assertSee($plan->plan_number)->assertSee('#1 (₹3,000.00), #2 (₹1,500.00)')->assertSee('₹25,500.00');
        $this->assertSame('application/pdf', $this->as('receptionist')->get("/payments/{$payment->id}/receipt.pdf")->assertOk()->headers->get('Content-Type'));
    }

    public function test_doctor_can_view_own_patients_plans_but_not_collect_or_create(): void
    {
        $plan = $this->plan();
        $login = User::factory()->create()->assignRole('doctor');
        $me = Doctor::factory()->create(['user_id' => $login->id]);
        $d = $this->actingAs($login);

        $d->get("/emi-plans/{$plan->id}")->assertForbidden();               // not their patient yet
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $me->id]);
        $d->get("/emi-plans/{$plan->id}")->assertOk()->assertDontSee('Collect payment')->assertDontSee('Cancel plan');
        $d->getJson('/emi-plans', ['X-Requested-With' => 'XMLHttpRequest'])->assertJsonPath('recordsTotal', 1);

        $d->post("/emi-plans/{$plan->id}/payments", ['amount' => '100', 'mode' => 'cash', 'payment_date' => today()->toDateString()])->assertForbidden();
        $d->get('/emi-plans/create')->assertForbidden();
        $d->post('/emi-plans', $this->payload())->assertForbidden();
    }
}
