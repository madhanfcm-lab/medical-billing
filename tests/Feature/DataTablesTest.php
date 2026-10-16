<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CallsDataTables;
use Tests\TestCase;

/**
 * Every server-side list: a real search finds the row, a non-matching search finds nothing,
 * and sorting by every column (including computed ones) does not blow up.
 */
class DataTablesTest extends TestCase
{
    use CallsDataTables, RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('admin'));

        $this->patient = Patient::factory()->create(['name' => 'Asha Verma', 'phone' => '9876543210']);
        $doctor = Doctor::factory()->create(['name' => 'Dr. Rao']);
        Visit::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'chief_complaint' => 'Toothache']);
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'reason' => 'Crown fitting']);
        Department::factory()->create(['name' => 'Orthodontics']);
        Service::factory()->create(['name' => 'Braces']);

        $svc = Service::factory()->create(['name' => 'Scaling']);
        $this->post('/invoices', ['patient_id' => $this->patient->id, 'invoice_date' => today()->toDateString(),
            'items' => [['service_id' => $svc->id, 'quantity' => 1, 'unit_price' => 1000]], 'pay_amount' => 400, 'pay_mode' => 'cash']);
        $this->post('/emi-plans', ['patient_id' => $this->patient->id, 'treatment_name' => 'Aligners', 'total_amount' => 9000, 'down_payment' => 0,
            'calc_by' => 'monthly', 'monthly_amount' => 3000, 'start_date' => today()->subMonths(2)->startOfMonth()->toDateString(), 'due_day' => 10]);
        // a second plan whose first instalment falls due in three days (feeds the "upcoming" list)
        $this->post('/emi-plans', ['patient_id' => $this->patient->id, 'treatment_name' => 'Retainers', 'total_amount' => 6000, 'down_payment' => 0,
            'calc_by' => 'months', 'months' => 2, 'start_date' => today()->toDateString(), 'due_day' => today()->addDays(3)->day]);
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: list<string>, 3: string, 4: array<string, string>}> */
    public static function lists(): array
    {
        return [
            'patients' => ['/patients', ['uhid', 'name', 'age', 'gender', 'phone', 'actions'], ['age', 'gender', 'actions'], 'Asha', []],
            'visits' => ['/visits', ['visit_date', 'patient', 'doctor', 'chief_complaint', 'actions'], ['doctor', 'actions'], 'Asha', []],
            'invoices' => ['/invoices', ['invoice_number', 'invoice_date', 'patient', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'],
                ['invoice_date', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'], 'Asha', []],
            'payments' => ['/payments', ['receipt_number', 'payment_date', 'patient', 'against', 'mode', 'amount', 'received_by', 'actions'],
                ['against', 'mode', 'amount', 'received_by', 'actions'], 'Asha', []],
            'emi plans' => ['/emi-plans', ['plan_number', 'patient', 'treatment_name', 'total_amount', 'paid', 'balance_amount', 'next_due_fmt', 'end_date', 'status'],
                ['total_amount', 'paid', 'balance_amount', 'next_due_fmt', 'end_date', 'status'], 'Aligners', []],
            'emi dues (overdue)' => ['/emi-dues', ['patient', 'phone', 'plan', 'installment_no', 'due_date', 'balance', 'days', 'actions'],
                ['phone', 'installment_no', 'due_date', 'balance', 'days', 'actions'], 'Asha', ['type' => 'overdue']],
            'emi dues (upcoming)' => ['/emi-dues', ['patient', 'phone', 'plan', 'installment_no', 'due_date', 'balance', 'days', 'actions'],
                ['phone', 'installment_no', 'due_date', 'balance', 'days', 'actions'], 'Asha', ['type' => 'upcoming']],
            'follow-ups' => ['/follow-ups', ['follow_up_date', 'patient', 'doctor', 'reason', 'status', 'actions'],
                ['follow_up_date', 'doctor', 'status', 'actions'], 'Crown', ['tab' => 'all']],
            'departments' => ['/departments', ['name', 'description', 'doctors_count', 'is_active', 'actions'], ['doctors_count', 'is_active', 'actions'], 'Ortho', []],
            'doctors' => ['/doctors', ['name', 'specialization', 'department', 'phone', 'consultation_fee', 'is_active', 'actions'],
                ['department', 'consultation_fee', 'is_active', 'actions'], 'Rao', []],
            'services' => ['/services', ['name', 'category', 'default_price', 'gst_percent', 'is_active', 'actions'], ['gst_percent', 'is_active', 'actions'], 'Braces', []],
            'users' => ['/users', ['name', 'email', 'phone', 'role', 'is_active', 'actions'], ['role', 'is_active', 'actions'], 'Administrator', []],
        ];
    }

    /** @dataProvider lists */
    public function test_search_finds_matches_and_rejects_non_matches(string $url, array $columns, array $notSearchable, string $term, array $extra): void
    {
        if ($url === '/users') {
            User::first()->update(['name' => 'Administrator']);
        }

        $hit = $this->dt($url, $columns, $term, $extra, $notSearchable)->assertOk();
        $this->assertGreaterThanOrEqual(1, $hit->json('recordsFiltered'), "'$term' should match something in $url");

        $miss = $this->dt($url, $columns, 'zzz-no-such-thing', $extra, $notSearchable)->assertOk();
        $this->assertSame(0, $miss->json('recordsFiltered'), "a non-matching search must return nothing in $url");
    }

    /** @dataProvider lists */
    public function test_sorting_by_every_column_works(string $url, array $columns, array $notSearchable, string $term, array $extra): void
    {
        foreach ($columns as $i => $name) {
            if ($name === 'actions') {
                continue;
            }
            foreach (['asc', 'desc'] as $dir) {
                $res = $this->dt($url, $columns, '', $extra, $notSearchable, $i, $dir);
                $this->assertSame(200, $res->getStatusCode(), "$url sorted by $name $dir");
            }
        }
    }

    public function test_invoice_status_and_date_filters_combine_with_search(): void
    {
        $cols = ['invoice_number', 'invoice_date', 'patient', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'];
        $ns = ['invoice_date', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'];

        $this->assertSame(1, $this->dt('/invoices', $cols, 'Asha', ['status' => 'partially_paid', 'due_only' => 1], $ns)->json('recordsFiltered'));
        $this->assertSame(0, $this->dt('/invoices', $cols, 'Asha', ['status' => 'paid'], $ns)->json('recordsFiltered'));
        $this->assertSame(0, $this->dt('/invoices', $cols, 'Asha', ['from' => today()->addDay()->toDateString()], $ns)->json('recordsFiltered'));
    }

    public function test_emi_status_filter(): void
    {
        $cols = ['plan_number', 'patient', 'treatment_name', 'total_amount', 'paid', 'balance_amount', 'next_due_fmt', 'end_date', 'status'];
        $ns = ['total_amount', 'paid', 'balance_amount', 'next_due_fmt', 'end_date', 'status'];

        $this->assertSame(2, $this->dt('/emi-plans', $cols, '', ['status' => 'active'], $ns)->json('recordsFiltered'));
        $this->assertSame(0, $this->dt('/emi-plans', $cols, '', ['status' => 'cancelled'], $ns)->json('recordsFiltered'));
    }

    public function test_follow_up_status_filter_on_all_tab(): void
    {
        FollowUp::factory()->create(['patient_id' => $this->patient->id, 'status' => FollowUpStatus::Cancelled]);
        $cols = ['follow_up_date', 'patient', 'doctor', 'reason', 'status', 'actions'];
        $ns = ['follow_up_date', 'doctor', 'status', 'actions'];

        $this->assertSame(1, $this->dt('/follow-ups', $cols, '', ['tab' => 'all', 'status' => 'cancelled'], $ns)->json('recordsFiltered'));
        $this->assertSame(2, $this->dt('/follow-ups', $cols, '', ['tab' => 'all'], $ns)->json('recordsFiltered'));
    }

    public function test_invoice_numbers_are_searchable(): void
    {
        $number = Invoice::firstOrFail()->invoice_number;
        $cols = ['invoice_number', 'invoice_date', 'patient', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'];

        $this->assertSame(1, $this->dt('/invoices', $cols, $number, [], ['invoice_date', 'grand_total', 'paid_amount', 'balance_amount', 'status', 'actions'])->json('recordsFiltered'));
        $this->assertSame(2, EmiPlan::count());
    }
}
