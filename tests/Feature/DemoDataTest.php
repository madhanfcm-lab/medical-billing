<?php

namespace Tests\Feature;

use App\Enums\FollowUpStatus;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\EmiInstallment;
use App\Models\EmiPaymentAllocation;
use App\Models\EmiPlan;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Reports\ReportRegistry;
use App\Services\DashboardService;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The seeded demo clinic doubles as a whole-system integration test: it is built through the real
 * services, so if any money rule, numbering rule or page is broken, something here fails.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    // --- the data itself -------------------------------------------------------------------------

    public function test_seeding_builds_the_expected_clinic(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(12, Patient::where('branch_id', Branch::main()->id)->count());
        $this->assertSame(3, Patient::where('branch_id', '!=', Branch::main()->id)->count());   // the second branch has its own patients
        $this->assertSame('PAT-'.now()->year.'-00001', Patient::min('uhid'));
        $this->assertSame('PAT-'.now()->year.'-00012', Patient::where('branch_id', Branch::main()->id)->max('uhid'));   // numbers keep counting across branches
        $this->assertSame(3, Doctor::count());
        $this->assertSame(4, Department::count());
        $this->assertGreaterThanOrEqual(10, Service::count());
        $this->assertSame(5, Employee::count());
        $this->assertSame(1, EmiPlan::count());
        $this->assertSame('Smile Dental & Family Clinic', setting('hospital_name'));

        foreach (['admin@clinic.test' => 'admin', 'rita@clinic.test' => 'receptionist', 'dr.rao@clinic.test' => 'doctor'] as $email => $role) {
            $this->assertTrue(User::where('email', $email)->firstOrFail()->hasRole($role), "$email should be $role");
        }
        $this->assertSame(Doctor::where('name', 'Dr. Anil Rao')->value('user_id'), User::where('email', 'dr.rao@clinic.test')->value('id'));
    }

    public function test_the_braces_plan_is_the_textbook_example(): void
    {
        $this->seed(DatabaseSeeder::class);
        $plan = EmiPlan::with('installments')->firstOrFail();

        $this->assertSame('Braces (orthodontics)', $plan->treatment_name);
        $this->assertSame('30000.00', $plan->total_amount);
        $this->assertSame('0.00', $plan->down_payment);
        $this->assertSame(10, $plan->months);
        $this->assertSame('3000.00', $plan->monthly_amount);
        $this->assertCount(10, $plan->installments);
        $this->assertSame(array_fill(0, 10, '3000.00'), $plan->installments->pluck('amount')->all());
        $this->assertSame(5, (int) $plan->due_day);

        // flexible payments: one instalment in full, one in part, which leaves overdue money to chase
        $this->assertSame('5000.00', $plan->paid_amount);
        $this->assertSame('25000.00', $plan->balance_amount);
        $this->assertSame('3000.00', $plan->installments[0]->paid_amount);
        $this->assertSame('2000.00', $plan->installments[1]->paid_amount);
        $this->assertGreaterThanOrEqual(1, EmiInstallment::overdue()->count());
        $this->assertSame(2, Payment::where('emi_plan_id', $plan->id)->count());
    }

    public function test_every_rupee_adds_up(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (Invoice::with('payments')->get() as $inv) {
            $paid = $inv->payments->sum(fn ($p) => to_paise($p->amount));
            $this->assertSame($paid, to_paise($inv->paid_amount), "{$inv->invoice_number}: paid_amount must equal its receipts");
            $expectedBalance = $inv->status === InvoiceStatus::Cancelled ? 0 : to_paise($inv->grand_total) - $paid;
            $this->assertSame($expectedBalance, to_paise($inv->balance_amount), "{$inv->invoice_number}: balance");
            $this->assertSame(to_paise($inv->subtotal) - to_paise($inv->discount_amount) + to_paise($inv->tax_amount), to_paise($inv->grand_total), "{$inv->invoice_number}: total");
            $this->assertSame(to_paise($inv->subtotal), $inv->items->sum(fn ($i) => to_paise($i->line_total) - to_paise($i->tax_amount)), "{$inv->invoice_number}: lines add up to the subtotal");
            if (to_paise($inv->discount_amount) > 0) {
                $this->assertNotEmpty($inv->discount_reason, "{$inv->invoice_number}: a discount needs its reason");
            }
        }

        $plan = EmiPlan::firstOrFail();
        $this->assertSame(to_paise($plan->balance_amount), $plan->installments->sum(fn ($i) => to_paise($i->balance)));
        $this->assertSame(to_paise($plan->paid_amount), EmiPaymentAllocation::query()->sum(\DB::raw('amount * 100')) > 0
            ? (int) round(EmiPaymentAllocation::query()->get()->sum(fn ($a) => to_paise($a->amount))) : 0);
        $this->assertSame(to_paise($plan->financed_amount), $plan->installments->sum(fn ($i) => to_paise($i->amount)));

        $this->assertSame(Payment::count(), Payment::distinct('receipt_number')->count());
        $this->assertSame(Invoice::count(), Invoice::distinct('invoice_number')->count());
    }

    public function test_invoice_and_receipt_numbers_have_no_gaps(): void
    {
        $this->seed(DatabaseSeeder::class);
        $year = now()->year;

        $this->assertSame(range(1, Invoice::count()), Invoice::orderBy('id')->pluck('invoice_number')->map(fn ($n) => (int) substr($n, -5))->all());
        $this->assertSame(range(1, Payment::count()), Payment::orderBy('id')->pluck('receipt_number')->map(fn ($n) => (int) substr($n, -5))->all());
        $this->assertStringStartsWith("INV-$year-", Invoice::first()->invoice_number);
        $this->assertStringStartsWith("RCP-$year-", Payment::first()->receipt_number);
    }

    public function test_a_mix_of_invoice_states_is_present(): void
    {
        $this->seed(DatabaseSeeder::class);
        $byStatus = Invoice::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        foreach (['paid', 'partially_paid', 'unpaid', 'cancelled'] as $s) {
            $this->assertGreaterThan(0, $byStatus[$s] ?? 0, "demo data should include a $s invoice");
        }
        $this->assertSame(1, Invoice::where('status', 'cancelled')->whereNotNull('cancel_reason')->count());
        $this->assertGreaterThan(0, Invoice::where('discount_type', 'percent')->count());
        $this->assertGreaterThan(0, Invoice::where('discount_type', 'amount')->count());
        $this->assertGreaterThan(0, InvoiceItem::where('gst_percent', '>', 0)->count());
        $this->assertEqualsCanonicalizing(['cash', 'upi', 'card'], Payment::where('type', 'invoice')->distinct()->pluck('mode')->map->value->all());
    }

    public function test_follow_ups_cover_every_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $by = FollowUp::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        $this->assertSame(1, $by[FollowUpStatus::Completed->value]);
        $this->assertSame(2, $by[FollowUpStatus::Missed->value]);            // flagged by the nightly job
        $this->assertSame(1, $by[FollowUpStatus::Rescheduled->value]);
        $this->assertSame(7, $by[FollowUpStatus::Scheduled->value]);
        $this->assertSame(2, FollowUp::today()->count());
        $this->assertSame(1, FollowUp::whereNotNull('rescheduled_from_id')->count());
        $this->assertSame(1, FollowUp::whereNotNull('emi_plan_id')->count());
    }

    public function test_attendance_is_unique_per_employee_per_day_and_salary_is_entered(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(50, Attendance::count());                           // 5 staff x 10 days
        $this->assertSame(50, Attendance::selectRaw('employee_id, attendance_date')->distinct()->get()->count());
        $this->assertGreaterThan(0, Attendance::where('status', 'absent')->count());
        $this->assertGreaterThan(0, Attendance::where('status', 'half_day')->count());
        $this->assertGreaterThan(0, Attendance::where('status', 'leave')->count());
        $this->assertSame(5, SalaryPayment::count());
        $this->assertSame(1, SalaryPayment::distinct('for_month')->count());
        $this->assertSame(0, SalaryPayment::where('mode', '!=', 'cash')->whereNull('reference_no')->count());
        $this->assertGreaterThan(0, app(DashboardService::class)->summary()['cards']['staff_present']);
    }

    public function test_the_audit_trail_was_written_while_seeding(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@clinic.test')->first();

        $this->assertGreaterThan(50, ActivityLog::count());
        $this->assertSame(Invoice::count(), ActivityLog::where('subject_type', 'invoice')->where('event', 'created')->count());
        $this->assertSame(Payment::count(), ActivityLog::where('subject_type', 'payment')->where('event', 'created')->count());
        $this->assertSame(1, ActivityLog::where('subject_type', 'invoice')->where('event', 'cancelled')->count());
        $this->assertSame(0, ActivityLog::whereNull('user_id')->where('subject_type', 'invoice')->count());   // seeded as the admin, not "System"
        $this->assertTrue(ActivityLog::where('user_id', $admin->id)->exists());
    }

    public function test_dashboard_numbers_and_chart_series_match_the_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $d = app(DashboardService::class)->summary();

        $todayPaid = Payment::whereDate('payment_date', today())->get()->sum(fn ($p) => to_paise($p->amount));
        $this->assertSame($todayPaid, to_paise($d['cards']['today_collection']));
        $this->assertGreaterThan(0, $d['cards']['today_collection']);
        $this->assertGreaterThan($d['cards']['today_collection'], $d['cards']['month_collection'] + 1);
        $this->assertSame(2, $d['cards']['today_followups']);
        $this->assertSame(EmiInstallment::overdue()->count(), $d['cards']['overdue_emis']);

        // outstanding = open invoice balances + stand-alone EMI balance (the plan here is not tied to an invoice)
        $this->assertEqualsWithDelta(
            (float) Invoice::where('status', '!=', 'cancelled')->sum('balance_amount') + 25000.0, $d['cards']['total_outstanding'], 0.005,
        );

        $this->assertCount(30, $d['collection_30_days']['labels']);
        $this->assertCount(30, $d['collection_30_days']['data']);
        $this->assertSame(end($d['collection_30_days']['data']) * 100, (float) $todayPaid);
        $inWindow = Payment::whereDate('payment_date', '>=', today()->subDays(29))->get()->sum(fn ($p) => to_paise($p->amount));
        $this->assertSame($inWindow, to_paise(array_sum($d['collection_30_days']['data'])));
        $this->assertSame($inWindow, to_paise(array_sum($d['collection_by_mode']['data'])));
        $this->assertEqualsCanonicalizing(['Cash', 'UPI', 'Card'], $d['collection_by_mode']['labels']);
        $this->assertLessThanOrEqual(10, $d['overdue_installments']->count());
        $this->assertGreaterThan(0, $d['recent_invoices']->count());

        $this->actingAs(User::where('email', 'admin@clinic.test')->first())->get('/dashboard')->assertOk()
            ->assertSee('id="chartCollection"', false)->assertSee('id="chartModes"', false)->assertDontSee('No payments in the last 30 days');
    }

    public function test_dashboard_charts_show_helpful_empty_states_on_a_fresh_system(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertDontSee('id="chartCollection"', false)->assertDontSee('id="chartModes"', false)
            ->assertSee('No payments in the last 30 days')->assertSee('No payments to split by mode yet')
            ->assertSee('No follow-ups scheduled for today')->assertSee('No overdue installments')->assertSee('No invoices yet');
    }

    // --- seeding behaviour ------------------------------------------------------------------------------

    public function test_seeding_twice_does_not_duplicate_or_crash(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [Patient::count(), Invoice::count(), Payment::count(), User::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [Patient::count(), Invoice::count(), Payment::count(), User::count()]);
    }

    public function test_seeded_logins_work_with_the_documented_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['admin@clinic.test' => AdminUserSeeder::DEMO_PASSWORD, 'rita@clinic.test' => DemoDataSeeder::DEMO_PASSWORD, 'dr.rao@clinic.test' => DemoDataSeeder::DEMO_PASSWORD] as $email => $password) {
            auth()->logout();
            $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect('/dashboard');
            $this->assertAuthenticated();
        }
    }

    /** The real `db:seed` command; production asks for confirmation unless --force is given. */
    private function seedForced(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }

    public function test_production_without_force_refuses_to_seed(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class])->expectsConfirmation('Are you sure you want to run this command?', 'no')->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_production_gets_no_demo_data_and_never_the_default_password(): void
    {
        $this->app['env'] = 'production';
        config(['clinic.admin.password' => null, 'clinic.admin.email' => 'owner@myclinic.example']);

        $this->seedForced();

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Invoice::count());
        $owner = User::where('email', 'owner@myclinic.example')->firstOrFail();
        $this->assertTrue($owner->hasRole('admin'));
        $this->assertFalse(Hash::check(AdminUserSeeder::DEMO_PASSWORD, $owner->password), 'production must not get the well-known demo password');
        $this->assertSame(1, User::count());
    }

    public function test_production_uses_the_password_from_the_environment_and_never_overwrites_it(): void
    {
        $this->app['env'] = 'production';
        config(['clinic.admin.password' => 'Str0ng-Production-Pass', 'clinic.admin.email' => 'owner@myclinic.example']);

        $this->seedForced();
        $owner = User::where('email', 'owner@myclinic.example')->firstOrFail();
        $this->assertTrue(Hash::check('Str0ng-Production-Pass', $owner->password));

        $owner->update(['password' => 'Changed-By-The-Owner-1']);
        config(['clinic.admin.password' => 'something-else']);
        $this->seedForced();                                         // re-running a seed must not reset a password the owner changed
        $this->assertTrue(Hash::check('Changed-By-The-Owner-1', $owner->fresh()->password));
        $this->assertSame(1, User::count());
    }

    // --- every screen, every role ---------------------------------------------------------------------------

    /** @return array<string, string> */
    private function adminPages(): array
    {
        $plan = EmiPlan::firstOrFail();

        return [
            '/dashboard', '/profile', '/patients', '/patients/create', '/patients/1', '/patients/1/edit', '/visits', '/visits/create', '/visits/1', '/visits/1/edit',
            '/follow-ups', '/follow-ups?tab=missed', '/follow-ups/create', '/follow-ups/'.FollowUp::first()->id,
            '/invoices', '/invoices/create', '/invoices/1', '/invoices/1/print', '/payments', '/payments/1/receipt',
            '/emi-plans', '/emi-plans/create', '/emi-plans/'.$plan->id, '/emi-dues',
            '/attendance', '/attendance/monthly', '/employees', '/employees/create', '/employees/1', '/employees/1/edit', '/salary-payments', '/salary-payments/create', '/salary-payments/1/edit',
            '/reports', ...array_map(fn ($k) => "/reports/$k", array_keys(ReportRegistry::all())), '/audit-log',
            '/departments', '/departments/create', '/doctors', '/doctors/create', '/services', '/services/create', '/settings', '/users', '/users/create',
        ];
    }

    public function test_every_screen_renders_for_the_admin_and_loads_scripts_in_a_working_order(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@clinic.test')->first());

        foreach ($this->adminPages() as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('</html>', $html, $url);
            $this->assertScriptsRunAfterJquery($html, $url);
        }
    }

    /**
     * Regression guard for a real bug: a partial put a <script> that used jQuery in the page body, which runs
     * before the libraries at the bottom of the layout are loaded ("$ is not defined").
     */
    private function assertScriptsRunAfterJquery(string $html, string $url): void
    {
        if (! preg_match('/<script[^>]+jquery[^>]*><\/script>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return;   // login / print views do not load the app libraries
        }
        $jqueryAt = $m[0][1];

        preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $html, $inline, PREG_OFFSET_CAPTURE);
        foreach ($inline[1] as [$code, $offset]) {
            if (preg_match('/\$\(|\$\.|jQuery|bootstrap\.|Chart\b|toastr|Swal|confirmAction|initPatientSelect|EmiPreview/', $code)) {
                $this->assertGreaterThan($jqueryAt, $offset, "$url: an inline script that needs jQuery/Bootstrap runs before they are loaded");
            }
        }
    }

    public function test_the_script_guard_would_catch_the_original_bug(): void
    {
        $bad = '<html><body><script>$(document).on("click", fn)</script><script src="https://code.jquery.com/jquery-3.7.1.min.js"></script></body></html>';

        $this->expectException(AssertionFailedError::class);
        $this->assertScriptsRunAfterJquery($bad, '/synthetic');
    }

    public function test_role_matrix_over_the_seeded_system(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rita = User::where('email', 'rita@clinic.test')->first();
        $rao = User::where('email', 'dr.rao@clinic.test')->first();

        // receptionist: front desk, billing, EMI, follow-ups, attendance: but no admin areas
        $this->actingAs($rita);
        foreach (['/dashboard', '/patients', '/patients/create', '/visits', '/invoices', '/invoices/create', '/invoices/1', '/payments', '/emi-plans', '/emi-plans/create', '/emi-dues', '/follow-ups', '/attendance', '/attendance/monthly'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/settings', '/users', '/doctors', '/services', '/departments', '/employees', '/salary-payments', '/reports', '/reports/invoices', '/audit-log'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/invoices/1')->assertDontSee('Audit trail')->assertDontSee('data-bs-target="#cancelModal"', false);

        // doctor: clinical screens only, and only their own patients
        $this->actingAs($rao);
        foreach (['/dashboard', '/patients', '/visits', '/visits/create', '/follow-ups', '/follow-ups/create', '/invoices', '/emi-plans'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/payments', '/invoices/create', '/emi-plans/create', '/attendance', '/settings', '/employees', '/reports', '/audit-log', '/patients/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $own = Patient::whereIn('id', Visit::where('doctor_id', Doctor::where('user_id', $rao->id)->value('id'))->select('patient_id'))->pluck('id');
        $this->assertGreaterThan(0, $own->count());
        $this->assertLessThan(Patient::count(), $own->count());
        $this->get('/patients/'.$own->first())->assertOk();
        $this->get('/patients/'.Patient::whereNotIn('id', $own)->value('id'))->assertForbidden();
        $this->getJson('/patients', ['X-Requested-With' => 'XMLHttpRequest'])->assertJsonPath('recordsTotal', $own->count());
    }

    public function test_every_report_and_export_works_on_the_demo_clinic(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@clinic.test')->first());

        foreach (array_keys(ReportRegistry::all()) as $key) {
            $this->get("/reports/$key")->assertOk();
            $x = $this->get("/reports/$key/excel")->assertOk();
            $this->assertGreaterThanOrEqual(1, IOFactory::load($x->baseResponse->getFile()->getPathname())->getSheetCount(), $key);
            $this->assertStringStartsWith('%PDF', $this->get("/reports/$key/pdf")->assertOk()->getContent());
        }

        // collection report agrees with the receipts
        $this->get('/reports/daily-collection?from='.today()->subYear()->toDateString())->assertOk()
            ->assertSee(money(Payment::where('branch_id', Branch::main()->id)->get()->sum(fn ($p) => to_paise($p->amount)) / 100));
    }
}
