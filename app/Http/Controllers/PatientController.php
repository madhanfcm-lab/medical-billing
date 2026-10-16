<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMode;
use App\Http\Requests\Patients\PatientRequest;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Services\PatientPaymentService;
use App\Services\PatientService;
use App\Services\PatientTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PatientController extends Controller
{
    public function __construct(private readonly PatientService $patients) {}

    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            return DataTables::eloquent(Patient::query()->visibleTo($request->user()))
                ->addColumn('age', fn (Patient $p) => $p->age_label)
                ->editColumn('gender', fn (Patient $p) => $p->gender->label())
                ->editColumn('name', fn (Patient $p) => '<a href="'.route('patients.show', $p).'" class="fw-semibold">'.e($p->name).'</a>')
                ->addColumn('actions', fn (Patient $p) => view('patients._actions', ['patient' => $p])->render())
                ->rawColumns(['name', 'actions'])
                ->orderColumn('age', 'date_of_birth desc')
                ->toJson();
        }

        return view('patients.index');
    }

    /** Unpaid bills of a patient that can still be put on an EMI plan (not cancelled, not already on a plan). */
    public function openBills(Patient $patient, PatientPaymentService $payments): JsonResponse
    {
        $this->authorize('view', $patient);

        return response()->json($payments->openInvoices($patient)->map(fn ($i) => [
            'id' => $i->id, 'number' => $i->invoice_number, 'date' => format_date($i->invoice_date),
            'balance' => (float) $i->balance_amount, 'balance_fmt' => money($i->balance_amount),
        ])->values());
    }

    /** Select2 AJAX source used by the top-bar search and every "pick a patient" field. */
    public function search(Request $request): JsonResponse
    {
        $results = Patient::query()->visibleTo($request->user())->search($request->string('q')->toString())
            ->withSum(['invoices as due' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'balance_amount')
            ->orderBy('name')->limit(20)->get(['id', 'uhid', 'name', 'phone', 'date_of_birth', 'gender'])
            ->map(fn (Patient $p) => [
                'id' => $p->id, 'text' => "{$p->name} · {$p->phone} · {$p->uhid}",
                'name' => $p->name, 'phone' => $p->phone, 'uhid' => $p->uhid,
                'meta' => trim($p->age_label.' · '.$p->gender->label(), ' ·—'), 'due' => (float) ($p->due ?? 0),
            ]);

        return response()->json(['results' => $results]);
    }

    public function create(): View
    {
        // The top-bar search offers "register new patient" and hands over what was typed.
        $typed = trim((string) request('q'));
        $prefill = preg_match('/^\d{10}$/', $typed) ? ['phone' => $typed] : ($typed !== '' ? ['name' => $typed] : []);

        return view('patients.form', ['patient' => new Patient($prefill)]);
    }

    public function store(PatientRequest $request): RedirectResponse
    {
        $patient = $this->patients->create($request->validated());

        // "Save & start bill": land on the patient page with the bill panel already open.
        $next = $request->input('after') === 'bill' && $request->user()->can('invoices.create') ? ['bill' => 1] : [];

        return redirect()->route('patients.show', ['patient' => $patient] + $next)
            ->with('success', "Patient registered as {$patient->uhid}.")
            ->with('just_registered', true);
    }

    public function show(Patient $patient, PatientTimeline $timeline): View
    {
        $this->authorize('view', $patient);
        $user = request()->user();

        return view('patients.show', [
            'patient' => $patient,
            'financials' => $this->patients->financials($patient),
            'dues' => $this->patients->dues($patient),
            'today' => $this->patients->today($patient),
            'timeline' => $timeline->for($patient),
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization', 'consultation_fee']),
            'billing' => $user->can('invoices.create') ? $this->billingConfig() : null,
            'visits' => $patient->visits()->with('doctor:id,name')->latest('visit_date')->latest('id')->limit(50)->get(),
            'invoices' => $patient->invoices()->latest('invoice_date')->latest('id')->limit(50)->get(),
            'appointments' => $user->can('appointments.view')
                ? $patient->appointments()->open()->whereDate('appointment_date', '>=', today()->toDateString())->with('doctor:id,name')->orderBy('appointment_date')->orderBy('appointment_time')->limit(5)->get()
                : collect(),
            'followUps' => $patient->followUps()->with('doctor:id,name')->latest('follow_up_date')->latest('id')->limit(50)->get(),
            'emiPlans' => $patient->emiPlans()->latest('id')->limit(50)->get(),
            'payments' => $patient->payments()->with(['invoice:id,invoice_number', 'emiPlan:id,plan_number'])->latest('payment_date')->latest('id')->limit(50)->get(),
        ]);
    }

    /** Services and payment modes for the bill panel on the patient page. @return array{services: mixed, modes: mixed} */
    private function billingConfig(): array
    {
        return [
            'services' => Service::active()->where('name', '!=', Service::CONSULTATION_NAME)->orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'default_price', 'gst_percent'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'group' => $s->category->label(), 'price' => (float) $s->default_price, 'gst' => (float) $s->gst_percent]),
            'modes' => collect(PaymentMode::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label(), 'ref' => $m->requiresReference()]),
        ];
    }

    public function edit(Patient $patient): View
    {
        return view('patients.form', compact('patient'));
    }

    public function update(PatientRequest $request, Patient $patient): RedirectResponse
    {
        $this->patients->update($patient, $request->validated());

        return redirect()->route('patients.show', $patient)->with('success', 'Patient details updated.');
    }
}
