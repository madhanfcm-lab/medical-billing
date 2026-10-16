<?php

namespace Database\Seeders;

use App\Enums\ConfirmationMode;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\SalaryPayment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Models\Visit;
use App\Services\AppointmentService;
use App\Services\AttendanceService;
use App\Services\EmiPaymentService;
use App\Services\EmiService;
use App\Services\FollowUpService;
use App\Services\InvoiceCalculator;
use App\Services\InvoiceService;
use App\Services\PatientService;
use App\Services\PaymentService;
use App\Support\CurrentBranch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A believable small dental/general clinic, built through the real services (so numbers, receipts,
 * UHIDs, audit entries and instalment schedules are exactly what the app itself would have produced).
 * Dates are relative to today, so the dashboard always has recent activity.
 *
 * Demo logins (see README): admin / receptionist / doctor, password Demo@12345 for the last two.
 */
class DemoDataSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Demo@12345';

    /** @var array<string, Doctor> */
    private array $doctors = [];

    /** @var array<string, Service> */
    private array $services = [];

    /** @var array<string, Patient> */
    private array $patients = [];

    private Branch $second;

    public function run(): void
    {
        if (Patient::query()->exists()) {
            $this->command?->warn('Demo data skipped: patients already exist. Use migrate:fresh --seed to start over.');

            return;
        }

        $this->call([RolesAndPermissionsSeeder::class, AdminUserSeeder::class]);
        $admin = User::where('email', config('clinic.admin.email'))->firstOrFail();
        Auth::login($admin);   // so created_by and the audit log name a real person

        DB::transaction(function () {
            $this->settings();
            $this->users();
            $this->masters();
            $this->patients();
            $this->billing();
            $this->emiPlan();
            $this->followUps();
            $this->team();
            $this->appointments();
            $this->secondBranch();
        });

        Auth::logout();
        $this->command?->info('Demo data loaded. Log in as admin@clinic.test / receptionist rita@clinic.test / doctor dr.rao@clinic.test.');
    }

    private function settings(): void
    {
        Setting::setMany([
            'hospital_name' => 'Smile Dental & Family Clinic',
            'hospital_address' => "Shop 4, Lotus Plaza, MG Road\nPune, Maharashtra 411001",
            'hospital_phone' => '020 2555 0142',
            'hospital_email' => 'hello@smileclinic.example',
            'hospital_gstin' => '27ABCDE1234F1Z5',
            'invoice_footer' => 'Thank you for choosing Smile Dental & Family Clinic. Get well soon!',
        ]);
    }

    private function users(): void
    {
        foreach ([
            ['Rita Sharma', 'rita@clinic.test', Role::Receptionist],
            ['Dr. Anil Rao', 'dr.rao@clinic.test', Role::Doctor],
        ] as [$name, $email, $role]) {
            User::create(['name' => $name, 'email' => $email, 'password' => self::DEMO_PASSWORD, 'is_active' => true])->assignRole($role->value);
        }

        // A second branch, so the branch switcher and per-branch data can be tried straight away.
        $main = Branch::main();
        $this->second = Branch::create(['name' => 'Kothrud Branch', 'code' => 'KOT', 'address' => "12, Karve Road, Kothrud\nPune, Maharashtra 411038", 'phone' => '020 2555 0177', 'is_active' => true]);
        User::where('email', config('clinic.admin.email'))->first()?->branches()->syncWithoutDetaching([$main->id]);
        User::where('email', 'rita@clinic.test')->first()->branches()->sync([$main->id, $this->second->id]);
        User::where('email', 'dr.rao@clinic.test')->first()->branches()->sync([$main->id, $this->second->id]);
    }

    private function masters(): void
    {
        $dept = collect(['General Medicine', 'Dental', 'Orthodontics', 'Pediatrics'])
            ->mapWithKeys(fn ($n) => [$n => Department::create(['name' => $n, 'is_active' => true])]);

        $rao = User::where('email', 'dr.rao@clinic.test')->first();
        foreach ([
            ['rao', 'Dr. Anil Rao', 'Orthodontist', 'Orthodontics', '9822001001', 500, $rao->id],
            ['iyer', 'Dr. Meera Iyer', 'Dentist (BDS, MDS)', 'Dental', '9822001002', 400, null],
            ['khan', 'Dr. Sana Khan', 'General Physician (MBBS)', 'General Medicine', '9822001003', 300, null],
        ] as [$key, $name, $spec, $deptName, $phone, $fee, $userId]) {
            $this->doctors[$key] = Doctor::create([
                'name' => $name, 'specialization' => $spec, 'department_id' => $dept[$deptName]->id,
                'phone' => $phone, 'consultation_fee' => $fee, 'user_id' => $userId, 'is_active' => true,
            ]);
        }

        foreach ([
            ['Consultation', 'consultation', 300, 0], ['Dental consultation', 'consultation', 400, 0],
            ['Scaling & polishing', 'procedure', 1200, 0], ['Tooth filling', 'procedure', 1500, 0],
            ['Root canal treatment', 'procedure', 6000, 0], ['Tooth extraction', 'procedure', 800, 0],
            ['Teeth whitening', 'procedure', 5000, 18], ['Braces (orthodontics)', 'procedure', 30000, 0],
            ['Wound dressing', 'other', 150, 0], ['Dental X-ray', 'lab', 200, 0], ['Complete blood count', 'lab', 350, 0],
            ['Medicines', 'pharmacy', 250, 12],
        ] as [$name, $cat, $price, $gst]) {
            $this->services[$name] = Service::create(['name' => $name, 'category' => $cat, 'default_price' => $price, 'gst_percent' => $gst, 'is_active' => true]);
        }
    }

    private function patients(): void
    {
        $svc = app(PatientService::class);
        foreach ([
            ['asha', 'Asha Verma', 'female', '1991-05-20', '9820011101', 'B+', 'Shivaji Nagar, Pune', 'Allergic to penicillin'],
            ['ravi', 'Ravi Kumar', 'male', '1985-11-02', '9820011102', 'O+', 'Kothrud, Pune', null],
            ['priya', 'Priya Sharma', 'female', '2004-03-14', '9820011103', 'A+', 'Baner, Pune', null],
            ['mohan', 'Mohan Deshmukh', 'male', '1958-08-30', '9820011104', 'AB+', 'Aundh, Pune', 'Diabetic, on metformin'],
            ['sneha', 'Sneha Kulkarni', 'female', '1996-01-09', '9820011105', 'O-', 'Wakad, Pune', null],
            ['vikram', 'Vikram Singh', 'male', '1979-07-25', '9820011106', 'B-', 'Hadapsar, Pune', 'Hypertension'],
            ['anita', 'Anita Joshi', 'female', '1950-12-18', '9820011107', 'A-', 'Deccan, Pune', 'Heart patient, avoid epinephrine'],
            ['rahul', 'Rahul Patil', 'male', '2000-09-05', '9820011108', 'O+', 'Pimple Saurabh, Pune', null],
            ['deepa', 'Deepa Nair', 'female', '1988-04-22', '9820011109', 'B+', 'Viman Nagar, Pune', null],
            ['karan', 'Karan Mehta', 'male', '2012-06-11', '9820011110', 'A+', 'Camp, Pune', null],
            ['neha', 'Neha Gupta', 'female', '1993-10-29', '9820011111', null, 'Kharadi, Pune', null],
            ['suresh', 'Suresh Reddy', 'male', '1967-02-16', '9820011112', 'AB-', 'Pashan, Pune', null],
        ] as [$key, $name, $gender, $dob, $phone, $blood, $address, $notes]) {
            $this->patients[$key] = $svc->create([
                'name' => $name, 'gender' => $gender, 'date_of_birth' => $dob, 'phone' => $phone,
                'blood_group' => $blood, 'address' => $address, 'allergies' => $notes,
                'emergency_contact_name' => 'Family member', 'emergency_contact_phone' => '98200'.substr($phone, 5, 5),
            ]);
        }
    }

    private function billing(): void
    {
        $invoices = app(InvoiceService::class);
        $payments = app(PaymentService::class);
        $calc = app(InvoiceCalculator::class);
        $complaints = ['Toothache', 'Bleeding gums', 'Routine check-up', 'Sensitivity to cold', 'Broken tooth', 'Fever and cold'];
        $diagnoses = ['Dental caries', 'Gingivitis', 'Healthy', 'Hypersensitivity', 'Fractured tooth', 'Viral fever'];

        // [days ago, patient, doctor, [[service, qty, item discount]], discount [type, value, reason]|null, pay: 'full'|amount, mode, reference, later: [days ago, amount, mode, ref]|null]
        $bills = [
            [29, 'mohan', 'khan', [['Consultation', 1, 0]], null, 'full', 'cash', null, null],
            [27, 'sneha', 'iyer', [['Dental consultation', 1, 0], ['Scaling & polishing', 1, 0]], null, 'full', 'upi', 'UPI270001', null],
            [25, 'vikram', 'iyer', [['Root canal treatment', 1, 0]], null, 3000, 'cash', null, [18, 3000, 'upi', 'UPI180002']],
            [22, 'anita', 'khan', [['Consultation', 1, 0], ['Complete blood count', 1, 0], ['Medicines', 2, 0]], ['percent', 5, 'Senior citizen'], 'full', 'cash', null, null],
            [20, 'rahul', 'iyer', [['Tooth filling', 2, 0]], null, 1500, 'card', 'CARD8841', null],
            [17, 'deepa', 'rao', [['Dental consultation', 1, 0], ['Dental X-ray', 2, 0]], null, 'full', 'upi', 'UPI170003', null],
            [15, 'karan', 'iyer', [['Tooth extraction', 1, 0]], null, 0, 'cash', null, null],
            [13, 'neha', 'khan', [['Consultation', 1, 0], ['Wound dressing', 1, 0]], null, 'full', 'cash', null, null],
            [11, 'suresh', 'iyer', [['Teeth whitening', 1, 0]], ['amount', 500, 'Festival offer'], 2000, 'upi', 'UPI110004', [4, 2500, 'cash', null]],
            [9, 'priya', 'rao', [['Consultation', 1, 0], ['Dental X-ray', 1, 0]], null, 'full', 'cash', null, null],
            [7, 'ravi', 'khan', [['Consultation', 1, 0], ['Complete blood count', 1, 0]], null, 'full', 'upi', 'UPI070005', null],
            [5, 'asha', 'iyer', [['Scaling & polishing', 1, 0], ['Tooth filling', 1, 0]], null, 1000, 'cash', null, null],
            [3, 'mohan', 'khan', [['Consultation', 1, 0]], null, 'full', 'cash', null, null],
            [2, 'sneha', 'iyer', [['Tooth filling', 1, 200]], null, 'full', 'card', 'CARD2201', null],
            [1, 'vikram', 'iyer', [['Wound dressing', 1, 0]], null, 'full', 'cash', null, null],
            [0, 'deepa', 'rao', [['Dental consultation', 1, 0]], null, 'full', 'cash', null, null],
            [0, 'anita', 'khan', [['Consultation', 1, 0], ['Medicines', 1, 0]], null, 'full', 'upi', 'UPI000006', null],
        ];

        foreach ($bills as $i => [$ago, $patient, $doctor, $lines, $discount, $pay, $mode, $ref, $later]) {
            $date = today()->subDays($ago)->toDateString();
            $visit = Visit::create([
                'patient_id' => $this->patients[$patient]->id, 'doctor_id' => $this->doctors[$doctor]->id, 'visit_date' => $date,
                'chief_complaint' => $complaints[$i % 6], 'diagnosis' => $diagnoses[$i % 6],
                'treatment_given' => implode(', ', array_column($lines, 0)),
            ]);

            $items = array_map(fn ($l) => [
                'service_id' => $this->services[$l[0]]->id, 'quantity' => $l[1], 'unit_price' => $this->services[$l[0]]->default_price,
                'discount_amount' => $l[2], 'gst_percent' => $this->services[$l[0]]->gst_percent,
            ], $lines);
            $totals = $calc->calculate($items, $discount[0] ?? null, $discount[1] ?? null);

            $invoice = $invoices->create([
                'patient_id' => $this->patients[$patient]->id, 'visit_id' => $visit->id, 'doctor_id' => $this->doctors[$doctor]->id,
                'invoice_date' => $date, 'items' => $items,
                'discount_type' => $discount[0] ?? null, 'discount_value' => $discount[1] ?? 0, 'discount_reason' => $discount[2] ?? null,
                'pay_amount' => $pay === 'full' ? $totals['grand_total'] : $pay, 'pay_mode' => $mode, 'pay_reference' => $ref,
            ]);

            if ($later) {
                $payments->collectForInvoice($invoice, ['amount' => $later[1], 'mode' => $later[2], 'reference_no' => $later[3], 'payment_date' => today()->subDays($later[0])->toDateString()]);
            }
        }

        // one invoice raised by mistake and cancelled
        $mistake = $invoices->create([
            'patient_id' => $this->patients['karan']->id, 'doctor_id' => $this->doctors['iyer']->id, 'invoice_date' => today()->subDays(8)->toDateString(),
            'items' => [['service_id' => $this->services['Consultation']->id, 'quantity' => 1, 'unit_price' => 300, 'gst_percent' => 0]], 'pay_amount' => 0,
        ]);
        $invoices->cancel($mistake, 'Billed to the wrong patient', Auth::user());
    }

    private function emiPlan(): void
    {
        $priya = $this->patients['priya'];
        $visit = Visit::create([
            'patient_id' => $priya->id, 'doctor_id' => $this->doctors['rao']->id, 'visit_date' => today()->subMonths(2)->startOfMonth()->addDays(2)->toDateString(),
            'chief_complaint' => 'Crooked teeth, wants braces', 'diagnosis' => 'Malocclusion', 'treatment_given' => 'Fixed metal braces fitted',
        ]);

        // The textbook case: braces ₹30,000, ₹3,000 a month, due on the 5th, started two months ago.
        $plan = app(EmiService::class)->create([
            'patient_id' => $priya->id, 'visit_id' => $visit->id, 'treatment_name' => 'Braces (orthodontics)',
            'total_amount' => 30000, 'down_payment' => 0, 'calc_by' => 'monthly', 'monthly_amount' => 3000,
            'start_date' => today()->subMonths(2)->startOfMonth()->toDateString(), 'due_day' => 5,
            'notes' => 'Doctor allowed ₹3,000 a month; flexible if the family needs more time.',
        ]);

        // Flexible payments: first instalment paid in full, the second only partly, so the plan shows one overdue instalment.
        $due = $plan->installments()->get();
        $collect = app(EmiPaymentService::class);
        $collect->collect($plan, ['amount' => 3000, 'mode' => 'upi', 'reference_no' => 'UPI-BRACES-1', 'payment_date' => min($due[0]->due_date, today())->toDateString()]);
        $collect->collect($plan, ['amount' => 2000, 'mode' => 'cash', 'payment_date' => min($due[1]->due_date->copy()->addDays(2), today())->toDateString(), 'notes' => 'Part payment, rest next week']);

        app(EmiService::class)->markOverdue();
    }

    private function followUps(): void
    {
        $f = app(FollowUpService::class);
        $make = fn (string $patient, string $doctor, int $inDays, ?string $time, string $reason, array $extra = []) => $f->create([
            'patient_id' => $this->patients[$patient]->id, 'doctor_id' => $this->doctors[$doctor]->id,
            'follow_up_date' => today()->addDays($inDays)->toDateString(), 'follow_up_time' => $time, 'reason' => $reason,
        ] + $extra);
        $plan = EmiPlan::firstOrFail();

        $make('priya', 'rao', 0, '10:30', 'Wire adjustment (braces)');
        $make('sneha', 'iyer', 0, '16:00', 'Check filling after 2 days');
        $make('vikram', 'iyer', 3, '11:00', 'Root canal: crown fitting');
        $make('asha', 'iyer', 7, null, 'Review after scaling');
        $make('mohan', 'khan', 14, '09:30', 'Blood sugar review');
        $make('priya', 'rao', 2, null, 'EMI payment reminder: braces', ['emi_plan_id' => $plan->id]);

        // history: two nobody turned up for (flagged Missed by the nightly job), one done, one moved
        $make('rahul', 'iyer', -3, '12:00', 'Second filling appointment');
        $make('karan', 'iyer', -6, null, 'Extraction aftercare check');
        $done = $make('deepa', 'rao', -1, '15:00', 'X-ray review');
        $f->complete($done);
        $moved = $make('suresh', 'iyer', -1, '17:00', 'Whitening touch-up');
        $f->reschedule($moved, ['follow_up_date' => today()->addDays(5)->toDateString(), 'follow_up_time' => '17:00', 'notes' => 'Patient was travelling']);

        $f->markMissed();
    }

    private function team(): void
    {
        $staff = [];
        foreach ([
            ['EMP-001', 'Sunita Patil', 'Receptionist', '9830011101', 3, 22000, 'bank_transfer'],
            ['EMP-002', 'Rakesh Jadhav', 'Dental assistant', '9830011102', 2, 18000, 'bank_transfer'],
            ['EMP-003', 'Pooja Nair', 'Nurse', '9830011103', 1, 24000, 'bank_transfer'],
            ['EMP-004', 'Imran Shaikh', 'Housekeeping', '9830011104', 2, 12000, 'cash'],
            ['EMP-005', 'Kavita Desai', 'Accountant (part-time)', '9830011105', 1, 15000, 'upi'],
        ] as [$code, $name, $role, $phone, $years, $salary, $mode]) {
            $staff[] = [Employee::create(['employee_code' => $code, 'name' => $name, 'designation' => $role, 'phone' => $phone,
                'joining_date' => today()->subYears($years)->subMonths(2)->toDateString(), 'is_active' => true]), $salary, $mode];
        }

        // ten days of attendance with a believable mix (Present most days)
        $attendance = app(AttendanceService::class);
        for ($day = 9; $day >= 0; $day--) {
            $rows = [];
            foreach ($staff as $i => [$employee]) {
                $roll = ($i * 3 + $day * 2) % 11;
                [$status, $remark] = match (true) {
                    $roll === 0 => ['absent', 'Unwell'],
                    $roll === 5 => ['half_day', 'Left early for a family function'],
                    $roll === 8 => ['leave', 'Planned leave'],
                    default => ['present', null],
                };
                $rows[$employee->id] = ['status' => $status, 'remark' => $remark];
            }
            $attendance->save(today()->subDays($day), $rows);
        }

        // last month's salary, entered by hand
        $forMonth = today()->subMonthNoOverflow()->startOfMonth();
        $paidOn = min($today = today(), $forMonth->copy()->addMonth()->addDays(2));
        foreach ($staff as $n => [$employee, $salary, $mode]) {
            SalaryPayment::create([
                'employee_id' => $employee->id, 'payment_date' => $paidOn->toDateString(), 'for_month' => $forMonth->toDateString(),
                'amount' => $salary, 'mode' => $mode, 'reference_no' => $mode === 'cash' ? null : 'REF'.$paidOn->format('md').sprintf('%03d', $n + 1),
                'notes' => $n === 0 ? 'Includes ₹1,000 festival bonus' : null,
            ]);
        }
    }

    /** A few bookings in every state, so Today / Upcoming / Needs confirmation all have something to show. */
    private function appointments(): void
    {
        $svc = app(AppointmentService::class);
        $book = fn (string $patient, string $doctor, int $inDays, string $time, string $reason) => $svc->book([
            'patient_id' => $this->patients[$patient]->id, 'doctor_id' => $this->doctors[$doctor]->id,
            'appointment_date' => today()->addDays($inDays)->toDateString(), 'appointment_time' => $time, 'duration_minutes' => 15, 'reason' => $reason,
        ]);

        $svc->confirm($book('neha', 'khan', 0, '17:30', 'Fever follow-up'), ConfirmationMode::Phone, 'Rang in the morning');
        $book('ravi', 'iyer', 0, '18:15', 'Dental check-up');
        $svc->confirm($book('asha', 'iyer', 1, '10:00', 'Scaling'), ConfirmationMode::WhatsApp);
        $book('suresh', 'iyer', 2, '11:30', 'Whitening touch-up');
        $book('karan', 'rao', 4, '16:00', 'Braces consultation');
        $svc->cancel($book('anita', 'khan', 3, '09:30', 'Blood test review'), 'Patient is travelling');
    }

    /** A little activity in the second branch, so switching branch visibly changes the lists. */
    private function secondBranch(): void
    {
        $current = app(CurrentBranch::class);
        $current->set($this->second, $this->second);

        // Branches do not share patients: Kothrud has its own people, registered there.
        $people = app(PatientService::class);
        $local = [];
        foreach ([['kot_ravi', 'Ravindra Kale', 'male', '1988-03-02', '9820022201'], ['kot_neha', 'Neha Phadke', 'female', '1993-11-21', '9820022202'], ['kot_mohan', 'Mohan Gokhale', 'male', '1962-06-09', '9820022203']] as [$key, $name, $gender, $dob, $phone]) {
            $local[$key] = $people->create(['name' => $name, 'gender' => $gender, 'date_of_birth' => $dob, 'phone' => $phone, 'address' => 'Kothrud, Pune']);
        }
        $this->patients += $local;

        $invoices = app(InvoiceService::class);
        foreach ([[6, 'kot_ravi', 'khan', 'Consultation', 'cash'], [4, 'kot_neha', 'iyer', 'Dental consultation', 'upi']] as [$ago, $patient, $doctor, $service, $mode]) {
            $date = today()->subDays($ago)->toDateString();
            $visit = Visit::create(['patient_id' => $this->patients[$patient]->id, 'doctor_id' => $this->doctors[$doctor]->id, 'visit_date' => $date, 'chief_complaint' => 'Check-up']);
            $price = $this->services[$service]->default_price;
            $invoices->create([
                'patient_id' => $this->patients[$patient]->id, 'visit_id' => $visit->id, 'doctor_id' => $this->doctors[$doctor]->id, 'invoice_date' => $date,
                'items' => [['service_id' => $this->services[$service]->id, 'quantity' => 1, 'unit_price' => $price, 'discount_amount' => 0, 'gst_percent' => $this->services[$service]->gst_percent]],
                'pay_amount' => $price, 'pay_mode' => $mode, 'pay_reference' => $mode === 'upi' ? 'UPI-KOT-1' : null,
            ]);
        }
        app(AppointmentService::class)->book([
            'patient_id' => $this->patients['kot_mohan']->id, 'doctor_id' => $this->doctors['khan']->id,
            'appointment_date' => today()->addDay()->toDateString(), 'appointment_time' => '12:00', 'duration_minutes' => 15, 'reason' => 'Blood sugar review',
        ]);

        $current->clear();
    }
}
