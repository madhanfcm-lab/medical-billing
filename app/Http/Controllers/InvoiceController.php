<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMode;
use App\Http\Requests\Billing\CancelInvoiceRequest;
use App\Http\Requests\Billing\StoreInvoiceRequest;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $user = $request->user();
            $query = Invoice::query()->with('patient:id,uhid,name')->select('invoices.*')
                ->when($user->hasRole('doctor'), fn ($q) => $q->whereIn('patient_id', Patient::visibleTo($user)->select('id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->input('to')))
                ->when($request->boolean('due_only'), fn ($q) => $q->where('balance_amount', '>', 0));

            return DataTables::eloquent($query)
                ->editColumn('invoice_number', fn (Invoice $i) => '<a href="'.route('invoices.show', $i).'" class="fw-semibold">'.e($i->invoice_number).'</a>')
                ->editColumn('invoice_date', fn (Invoice $i) => format_date($i->invoice_date))
                ->addColumn('patient', fn (Invoice $i) => e($i->patient->name).'<div class="small text-muted">'.e($i->patient->uhid).'</div>')
                ->editColumn('grand_total', fn (Invoice $i) => money($i->grand_total))
                ->editColumn('paid_amount', fn (Invoice $i) => money($i->paid_amount))
                ->editColumn('balance_amount', fn (Invoice $i) => money($i->balance_amount))
                ->editColumn('status', fn (Invoice $i) => view('partials.invoice-status', ['status' => $i->status])->render())
                ->addColumn('actions', fn (Invoice $i) => view('invoices._actions', ['invoice' => $i])->render())
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($p) => $p->search($kw)))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->rawColumns(['invoice_number', 'patient', 'status', 'actions'])
                ->toJson();
        }

        return view('invoices.index', ['statuses' => InvoiceStatus::cases()]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $visit = $request->filled('visit') ? Visit::with(['patient', 'doctor'])->find($request->integer('visit')) : null;
        $patient = $visit?->patient
            ?? ($request->filled('patient') ? Patient::query()->visibleTo($user)->find($request->integer('patient')) : null);

        return view('invoices.create', [
            'patient' => $patient,
            'visit' => $visit,
            'doctorId' => $visit?->doctor_id,
            'hasVisitToday' => $patient ? Visit::where('patient_id', $patient->id)->whereDate('visit_date', today())->exists() : false,
            'previousDue' => '0.00',
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization', 'consultation_fee']),
            'services' => Service::active()->where('name', '!=', Service::CONSULTATION_NAME)->orderBy('category')->orderBy('name')
                ->get(['id', 'name', 'category', 'default_price', 'gst_percent'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'group' => $s->category->label(), 'price' => (float) $s->default_price, 'gst' => (float) $s->gst_percent]),
            'modes' => collect(PaymentMode::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label(), 'ref' => $m->requiresReference()]),
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $invoice = $this->invoices->create($request->validated());

        // Billing from the patient page: stay on the patient page, with print links for the bill and any receipt.
        if ($request->validated('return_to') === 'patient') {
            return redirect()->route('patients.show', $invoice->patient_id)
                ->with('success', "Bill {$invoice->invoice_number} saved.")
                ->with('bill_id', $invoice->id)
                ->with('receipt_id', $invoice->payments()->latest('id')->value('id'));
        }

        return redirect()->route('invoices.show', $invoice)->with('success', "Invoice {$invoice->invoice_number} created.");
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view('invoices.show', [
            'invoice' => $invoice->load(['patient', 'doctor', 'items', 'payments' => fn ($q) => $q->orderBy('id'), 'cancelledBy', 'visit']),
            'modes' => PaymentMode::options(),
            'audit' => request()->user()->can('audit.view') ? $invoice->activities()->with('user:id,name')->get() : null,
        ]);
    }

    public function cancel(CancelInvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('cancel', $invoice);
        $this->invoices->cancel($invoice, $request->validated('reason'), $request->user());

        return redirect()->route('invoices.show', $invoice)->with('success', 'Invoice cancelled.');
    }

    /** Print-friendly A4 page (browser print dialog). */
    public function print(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        return view('invoices.document', ['invoice' => $this->loadForDocument($invoice), 'pdf' => false]);
    }

    public function pdf(Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        return Pdf::loadView('invoices.document', ['invoice' => $this->loadForDocument($invoice), 'pdf' => true])
            ->setPaper('a4')
            ->download($invoice->invoice_number.'.pdf');
    }

    private function loadForDocument(Invoice $invoice): Invoice
    {
        return $invoice->load(['patient', 'doctor', 'items', 'payments' => fn ($q) => $q->orderBy('id')]);
    }
}
