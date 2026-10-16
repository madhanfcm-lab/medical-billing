<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private User $admin;

    private User $rita;

    private Patient $patient;

    private Service $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'Admin User'])->assignRole('admin');
        $this->rita = User::factory()->create(['name' => 'Rita'])->assignRole('receptionist');
        $this->patient = Patient::factory()->create(['name' => 'Asha Verma']);
        $this->svc = Service::factory()->create();
    }

    private function invoice(array $extra = [], ?User $by = null): Invoice
    {
        $this->actingAs($by ?? $this->rita)->post('/invoices', array_merge([
            'patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 5000]],
        ], $extra))->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function logsFor(string $type, int $id)
    {
        return ActivityLog::where('subject_type', $type)->where('subject_id', $id)->orderBy('id')->get();
    }

    // --- invoices & payments --------------------------------------------------------------

    public function test_invoice_creation_is_logged_with_who_and_what(): void
    {
        $inv = $this->invoice();
        $log = $this->logsFor('invoice', $inv->id)->first();

        $this->assertSame('created', $log->event);
        $this->assertSame($this->rita->id, $log->user_id);
        $this->assertSame($inv->invoice_number, $log->subject_label);
        $this->assertSame("Invoice {$inv->invoice_number} created", $log->description);
        $this->assertNull($log->old_values);
        $this->assertSame($inv->invoice_number, $log->new_values['invoice_number']);
        $this->assertEquals(5000, $log->new_values['grand_total']);
        $this->assertSame('unpaid', $log->new_values['status']);
        $this->assertArrayNotHasKey('updated_at', $log->new_values);          // housekeeping columns are not noise
        $this->assertArrayNotHasKey('created_by', $log->new_values);
        $this->assertNotNull($log->created_at);
    }

    public function test_payments_are_logged_and_the_invoice_change_has_old_and_new_values(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->rita)->post("/invoices/{$inv->id}/payments", ['amount' => 2000, 'mode' => 'upi', 'reference_no' => 'U1', 'payment_date' => today()->toDateString()]);

        $payment = Payment::firstOrFail();
        $pl = $this->logsFor('payment', $payment->id)->first();
        $this->assertSame('created', $pl->event);
        $this->assertSame($payment->receipt_number, $pl->subject_label);
        $this->assertEquals(2000, $pl->new_values['amount']);
        $this->assertSame('upi', $pl->new_values['mode']);

        $upd = $this->logsFor('invoice', $inv->id)->last();
        $this->assertSame('updated', $upd->event);
        $this->assertEquals(0, $upd->old_values['paid_amount']);
        $this->assertEquals(2000, $upd->new_values['paid_amount']);
        $this->assertEquals(5000, $upd->old_values['balance_amount']);
        $this->assertEquals(3000, $upd->new_values['balance_amount']);
        $this->assertSame('unpaid', $upd->old_values['status']);
        $this->assertSame('partially_paid', $upd->new_values['status']);
        $this->assertSame($this->rita->id, $upd->user_id);
        $this->assertArrayNotHasKey('updated_by', $upd->new_values);
    }

    public function test_cancelling_an_invoice_is_its_own_event_with_the_reason(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->admin)->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake']);

        $log = $this->logsFor('invoice', $inv->id)->last();
        $this->assertSame('cancelled', $log->event);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('unpaid', $log->old_values['status']);
        $this->assertSame('cancelled', $log->new_values['status']);
        $this->assertSame('Entered by mistake', $log->new_values['cancel_reason']);
        $this->assertSame("Invoice {$inv->invoice_number} cancelled", $log->description);
    }

    public function test_a_save_that_changes_nothing_logs_nothing(): void
    {
        $inv = $this->invoice();
        $before = ActivityLog::count();

        $inv->save();                                  // nothing dirty
        $inv->update(['notes' => $inv->notes]);       // same value
        $this->assertSame($before, ActivityLog::count());

        $inv->touch();                                 // only updated_at
        $this->assertSame($before, ActivityLog::count());
    }

    public function test_the_log_is_written_in_the_same_transaction_as_the_change(): void
    {
        $this->actingAs($this->rita);
        $data = ['patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $this->svc->id, 'quantity' => 1, 'unit_price' => 100]], 'pay_amount' => 0];

        DB::beginTransaction();
        app(InvoiceService::class)->create($data);
        $this->assertSame(1, ActivityLog::count());
        DB::rollBack();

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, ActivityLog::count());    // no log line without its change
    }

    // --- EMI ------------------------------------------------------------------------------------

    private function plan(): EmiPlan
    {
        $this->actingAs($this->rita)->post('/emi-plans', [
            'patient_id' => $this->patient->id, 'treatment_name' => 'Braces', 'total_amount' => 30000, 'down_payment' => 0,
            'calc_by' => 'monthly', 'monthly_amount' => 3000, 'start_date' => today()->toDateString(), 'due_day' => 15,
        ])->assertSessionHasNoErrors();

        return EmiPlan::firstOrFail();
    }

    public function test_emi_plan_lifecycle_is_logged(): void
    {
        $plan = $this->plan();
        $this->assertSame('created', $this->logsFor('emi_plan', $plan->id)->first()->event);

        $this->actingAs($this->rita)->post("/emi-plans/{$plan->id}/payments", ['amount' => 3000, 'mode' => 'cash', 'payment_date' => today()->toDateString()]);
        $pay = $this->logsFor('emi_plan', $plan->id)->last();
        $this->assertSame('updated', $pay->event);
        $this->assertEquals(30000, $pay->old_values['balance_amount']);
        $this->assertEquals(27000, $pay->new_values['balance_amount']);
        $this->assertEquals(3000, $pay->new_values['paid_amount']);

        $this->actingAs($this->admin)->post("/emi-plans/{$plan->id}/default");
        $this->assertSame('defaulted', $this->logsFor('emi_plan', $plan->id)->last()->event);

        $this->actingAs($this->admin)->post("/emi-plans/{$plan->id}/reschedule", ['calc_by' => 'months', 'months' => 3, 'start_date' => today()->toDateString(), 'due_day' => 20, 'reason' => 'Longer period']);
        $resched = $this->logsFor('emi_plan', $plan->id)->last();
        $this->assertSame('rescheduled', $resched->event);
        $this->assertArrayHasKey('end_date', $resched->new_values);
        $this->assertEquals(15, $resched->old_values['due_day']);
        $this->assertEquals(20, $resched->new_values['due_day']);

        $this->actingAs($this->admin)->post("/emi-plans/{$plan->id}/cancel", ['reason' => 'Patient left']);
        $cancel = $this->logsFor('emi_plan', $plan->id)->last();
        $this->assertSame('cancelled', $cancel->event);
        $this->assertSame($this->admin->id, $cancel->user_id);
        $this->assertSame('Patient left', $cancel->new_values['cancel_reason']);
    }

    public function test_completing_and_reactivating_a_plan_are_named_events(): void
    {
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->patient->id, 'treatment_name' => 'Scaling', 'total_amount' => 3000, 'down_payment' => 0,
            'calc_by' => 'months', 'months' => 1, 'start_date' => today()->toDateString(), 'due_day' => 15]);
        $plan = EmiPlan::firstOrFail();

        $this->actingAs($this->admin)->post("/emi-plans/{$plan->id}/default");
        $this->actingAs($this->admin)->post("/emi-plans/{$plan->id}/reactivate");
        $this->assertSame('reactivated', $this->logsFor('emi_plan', $plan->id)->last()->event);

        $this->actingAs($this->rita)->post("/emi-plans/{$plan->id}/payments", ['amount' => 3000, 'mode' => 'cash', 'payment_date' => today()->toDateString()]);
        $this->assertSame('completed', $this->logsFor('emi_plan', $plan->id)->last()->event);
    }

    public function test_emi_down_payment_and_invoice_link_are_audited(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->rita)->post('/emi-plans', ['patient_id' => $this->patient->id, 'invoice_id' => $inv->id, 'treatment_name' => 'X', 'total_amount' => 5000,
            'down_payment' => 1000, 'pay_mode' => 'cash', 'calc_by' => 'months', 'months' => 4, 'start_date' => today()->toDateString(), 'due_day' => 10]);

        $this->assertSame(1, ActivityLog::where('subject_type', 'payment')->count());
        $upd = $this->logsFor('invoice', $inv->id)->last();
        $this->assertEquals(1000, $upd->new_values['paid_amount']);
    }

    // --- attendance ------------------------------------------------------------------------------

    public function test_attendance_edits_keep_old_and_new_values(): void
    {
        $e = Employee::factory()->create(['name' => 'Asha Nair']);
        $date = today()->toDateString();
        $this->actingAs($this->rita)->post('/attendance', ['date' => $date, 'attendance' => [$e->id => ['status' => 'present']]]);
        $att = Attendance::firstOrFail();

        $created = $this->logsFor('attendance', $att->id)->first();
        $this->assertSame('created', $created->event);
        $this->assertSame('present', $created->new_values['status']);
        $this->assertSame('Asha Nair · '.format_date($date), $created->subject_label);

        $this->actingAs($this->admin)->post('/attendance', ['date' => $date, 'attendance' => [$e->id => ['status' => 'absent', 'remark' => 'No call']]]);
        $edit = $this->logsFor('attendance', $att->id)->last();
        $this->assertSame('updated', $edit->event);
        $this->assertSame($this->admin->id, $edit->user_id);
        $this->assertSame('present', $edit->old_values['status']);
        $this->assertSame('absent', $edit->new_values['status']);
        $this->assertNull($edit->old_values['remark']);
        $this->assertSame('No call', $edit->new_values['remark']);

        // saving the identical sheet again must not add noise
        $count = ActivityLog::count();
        $this->actingAs($this->admin)->post('/attendance', ['date' => $date, 'attendance' => [$e->id => ['status' => 'absent', 'remark' => 'No call']]]);
        $this->assertSame($count, ActivityLog::count());
    }

    // --- salary ----------------------------------------------------------------------------------

    public function test_salary_entries_are_audited_including_delete(): void
    {
        $e = Employee::factory()->create(['name' => 'Ravi Das']);
        $this->actingAs($this->admin)->post('/salary-payments', ['employee_id' => $e->id, 'payment_date' => today()->toDateString(), 'for_month' => today()->format('Y-m'), 'amount' => 12000, 'mode' => 'cash']);
        $p = SalaryPayment::firstOrFail();
        $this->assertSame('created', $this->logsFor('salary_payment', $p->id)->first()->event);

        $this->actingAs($this->admin)->put("/salary-payments/{$p->id}", ['employee_id' => $e->id, 'payment_date' => today()->toDateString(), 'for_month' => today()->format('Y-m'), 'amount' => 12500, 'mode' => 'cash']);
        $upd = $this->logsFor('salary_payment', $p->id)->last();
        $this->assertEquals(12000, $upd->old_values['amount']);
        $this->assertEquals(12500, $upd->new_values['amount']);

        $this->actingAs($this->admin)->delete("/salary-payments/{$p->id}");
        $del = $this->logsFor('salary_payment', $p->id)->last();
        $this->assertSame('deleted', $del->event);
        $this->assertStringContainsString('Ravi Das', $del->subject_label);
    }

    // --- what is NOT audited ------------------------------------------------------------------------

    public function test_other_records_and_system_jobs_do_not_write_log_lines(): void
    {
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'follow_up_date' => today()->subDays(2)->toDateString()]);
        Doctor::factory()->create();
        $before = ActivityLog::count();

        $this->artisan('followups:mark-missed')->assertSuccessful();
        $this->artisan('emi:mark-overdue')->assertSuccessful();

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_changes_made_without_a_signed_in_user_are_attributed_to_the_system(): void
    {
        $inv = $this->invoice();
        auth()->logout();
        $inv->refresh()->update(['notes' => 'edited by a job']);

        $log = $this->logsFor('invoice', $inv->id)->last();
        $this->assertNull($log->user_id);
        $this->assertNull($log->ip_address);          // console / no request
    }

    // --- the audit page ----------------------------------------------------------------------------

    public function test_only_admin_can_open_the_audit_log(): void
    {
        $this->actingAs($this->rita)->get('/audit-log')->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole('doctor'))->get('/audit-log')->assertForbidden();
        $this->actingAs($this->admin)->get('/audit-log')->assertOk()->assertSee('Audit log');
    }

    public function test_audit_feed_filters_search_and_renders_changes(): void
    {
        $inv = $this->invoice();
        $this->actingAs($this->rita)->post("/invoices/{$inv->id}/payments", ['amount' => 2000, 'mode' => 'cash', 'payment_date' => today()->toDateString()]);
        $this->actingAs($this->admin)->post("/invoices/{$inv->id}/cancel", ['reason' => 'Entered by mistake']);
        $this->actingAs($this->admin);

        $cols = ['created_at', 'user', 'event', 'record', 'changes'];
        $ns = ['created_at', 'event', 'changes'];
        $feed = fn (string $search = '', array $extra = []) => $this->dt('/audit-log', $cols, $search, $extra, $ns, 0, 'asc');

        $all = $feed()->assertOk();
        $this->assertSame(ActivityLog::count(), $all->json('recordsTotal'));

        $this->assertSame(1, $feed('', ['event' => 'cancelled'])->json('recordsFiltered'));
        $this->assertSame(1, $feed('', ['type' => 'payment'])->json('recordsFiltered'));
        $this->assertSame(ActivityLog::where('user_id', $this->rita->id)->count(), $feed('', ['user_id' => $this->rita->id])->json('recordsFiltered'));
        $this->assertSame(0, $feed('', ['from' => today()->addDay()->toDateString()])->json('recordsFiltered'));
        $this->assertGreaterThan(0, $feed('', ['to' => today()->toDateString()])->json('recordsFiltered'));

        $this->assertSame(ActivityLog::where('subject_label', $inv->invoice_number)->count(), $feed($inv->invoice_number)->json('recordsFiltered'));   // 3 lines: created, paid, cancelled
        $this->assertSame(0, $feed('zzz-nothing')->json('recordsFiltered'));
        $this->assertGreaterThan(0, $feed('Rita')->json('recordsFiltered'));          // search by user name
        $this->assertSame(0, $feed('Nobody Here')->json('recordsFiltered'));

        $cancelRow = collect($feed('', ['event' => 'cancelled'])->json('data'))->first();
        $this->assertStringContainsString('Admin User', $cancelRow['user']);
        $this->assertStringContainsString('Cancelled', $cancelRow['event']);
        $this->assertStringContainsString(route('invoices.show', $inv->id), $cancelRow['record']);
        $this->assertStringContainsString('Before', $cancelRow['changes']);
        $this->assertStringContainsString('Partially Paid', $cancelRow['changes']);   // it had a payment before it was cancelled
        $this->assertStringContainsString('Cancelled', $cancelRow['changes']);
        $this->assertStringContainsString('Entered by mistake', $cancelRow['changes']);

        foreach ($cols as $i => $c) {
            foreach (['asc', 'desc'] as $dir) {
                $this->dt('/audit-log', $cols, '', [], $ns, $i, $dir)->assertOk();
            }
        }
    }

    public function test_money_and_dates_are_shown_in_indian_format_in_the_change_table(): void
    {
        $this->assertSame('₹1,23,456.50', ActivityLog::formatValue('grand_total', '123456.5'));
        $this->assertSame('₹0.00', ActivityLog::formatValue('balance_amount', 0));
        $this->assertSame('15-10-2026', ActivityLog::formatValue('payment_date', '2026-10-15'));
        $this->assertSame('Partially Paid', ActivityLog::formatValue('status', 'partially_paid'));
        $this->assertSame('—', ActivityLog::formatValue('notes', null));
        $this->assertSame('UPI123', ActivityLog::formatValue('reference_no', 'UPI123'));
    }

    public function test_record_links_resolve_for_every_audited_type(): void
    {
        $inv = $this->invoice(['pay_amount' => 1000, 'pay_mode' => 'cash']);
        $e = Employee::factory()->create();
        $this->actingAs($this->rita)->post('/attendance', ['date' => today()->toDateString(), 'attendance' => [$e->id => ['status' => 'present']]]);
        $this->actingAs($this->admin)->post('/salary-payments', ['employee_id' => $e->id, 'payment_date' => today()->toDateString(), 'for_month' => today()->format('Y-m'), 'amount' => 100, 'mode' => 'cash']);
        $plan = $this->plan();

        $urls = ActivityLog::with('subject')->get()->groupBy('subject_type')->map(fn ($g) => $g->first()->subjectUrl());
        $this->assertSame(route('invoices.show', $inv->id), $urls['invoice']);
        $this->assertSame(route('payments.receipt', Payment::first()->id), $urls['payment']);
        $this->assertSame(route('emi-plans.show', $plan->id), $urls['emi_plan']);
        $this->assertSame(route('attendance.index', ['date' => today()->toDateString()]), $urls['attendance']);
        $this->assertSame(route('salary-payments.edit', SalaryPayment::first()->id), $urls['salary_payment']);

        $this->actingAs($this->admin)->delete('/salary-payments/'.SalaryPayment::first()->id);
        $this->assertNull(ActivityLog::where('subject_type', 'salary_payment')->latest('id')->first()->subjectUrl());   // deleted: no page to open
        $this->actingAs($this->admin)->get('/audit-log')->assertOk();
    }

    public function test_per_record_trail_is_shown_to_admins_only(): void
    {
        $inv = $this->invoice(['pay_amount' => 1000, 'pay_mode' => 'cash']);
        $plan = $this->plan();

        $this->actingAs($this->admin)->get("/invoices/{$inv->id}")->assertOk()->assertSee('Audit trail')->assertSee('Rita')->assertSee('Partially Paid');
        $this->actingAs($this->admin)->get("/emi-plans/{$plan->id}")->assertOk()->assertSee('Audit trail');
        $this->actingAs($this->rita)->get("/invoices/{$inv->id}")->assertOk()->assertDontSee('Audit trail');
        $this->actingAs($this->rita)->get("/emi-plans/{$plan->id}")->assertOk()->assertDontSee('Audit trail');
    }

    public function test_the_log_is_append_only_in_the_app(): void
    {
        $this->assertFalse(\Route::has('audit.destroy'));
        $this->assertFalse(\Route::has('audit.update'));
        $this->assertSame(['GET', 'HEAD'], \Route::getRoutes()->getByName('audit.index')->methods());
        $this->actingAs($this->admin)->delete('/audit-log')->assertStatus(405);
    }

    public function test_morph_aliases_are_short_and_do_not_break_roles(): void
    {
        $this->assertSame('invoice', (new Invoice)->getMorphClass());
        $this->assertSame('emi_plan', (new EmiPlan)->getMorphClass());
        $this->assertSame(User::class, (new User)->getMorphClass());        // spatie roles keep using the class name
        $this->assertTrue($this->admin->fresh()->hasRole('admin'));
    }
}
