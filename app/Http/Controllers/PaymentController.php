<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMode;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PaymentController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $query = Payment::query()->with(['patient:id,uhid,name', 'invoice:id,invoice_number', 'emiPlan:id,plan_number', 'creator:id,name'])->select('payments.*')
                ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->input('mode')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('payment_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('payment_date', '<=', $request->input('to')));

            return DataTables::eloquent($query)
                ->editColumn('receipt_number', fn (Payment $p) => '<a href="'.route('payments.receipt', $p).'" class="fw-semibold">'.e($p->receipt_number).'</a>')
                ->editColumn('payment_date', fn (Payment $p) => format_date($p->payment_date))
                ->addColumn('patient', fn (Payment $p) => '<a href="'.route('patients.show', $p->patient_id).'">'.e($p->patient->name).'</a>')
                ->addColumn('against', fn (Payment $p) => e($p->emiPlan?->plan_number ?? $p->invoice?->invoice_number ?? '—'))
                ->editColumn('mode', fn (Payment $p) => e($p->mode->label()))
                ->editColumn('amount', fn (Payment $p) => money($p->amount))
                ->addColumn('received_by', fn (Payment $p) => e($p->creator?->name ?? '—'))
                ->addColumn('actions', fn (Payment $p) => '<a href="'.route('payments.receipt', $p).'" class="btn btn-sm btn-outline-secondary" title="Receipt"><i class="bi bi-receipt"></i></a>')
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($p) => $p->search($kw)))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->orderColumn('against', fn ($q, $dir) => $q->orderBy('invoice_id', $dir))
                ->orderColumn('received_by', fn ($q, $dir) => $q->orderBy('created_by', $dir))
                ->rawColumns(['receipt_number', 'patient', 'actions'])
                ->toJson();
        }

        return view('payments.index', ['modes' => PaymentMode::options()]);
    }

    public function receipt(Payment $payment): View
    {
        return view('payments.receipt', ['payment' => $this->loadForDocument($payment), 'pdf' => false]);
    }

    public function pdf(Payment $payment): Response
    {
        return Pdf::loadView('payments.receipt', ['payment' => $this->loadForDocument($payment), 'pdf' => true])
            ->setPaper('a5', 'landscape')
            ->download($payment->receipt_number.'.pdf');
    }

    /** One combined receipt for everything taken in a single "Receive payment" action. */
    public function batch(string $ref): View|RedirectResponse
    {
        $payments = $this->loadBatch($ref);

        return $payments->count() === 1
            ? redirect()->route('payments.receipt', $payments->first())
            : view('payments.batch', ['payments' => $payments, 'pdf' => false, 'ref' => $ref]);
    }

    public function batchPdf(string $ref): Response
    {
        $payments = $this->loadBatch($ref);

        return Pdf::loadView('payments.batch', ['payments' => $payments, 'pdf' => true, 'ref' => $ref])
            ->setPaper('a5', 'landscape')
            ->download('receipt-'.$payments->first()->receipt_number.'.pdf');
    }

    /** @return Collection<int, Payment> */
    private function loadBatch(string $ref): Collection
    {
        $payments = Payment::where('batch_ref', $ref)->with(['patient', 'invoice', 'creator'])->orderBy('id')->get();
        abort_if($payments->isEmpty(), 404);
        $this->authorize('view', $payments->first());

        return $payments;
    }

    private function loadForDocument(Payment $payment): Payment
    {
        $this->authorize('view', $payment);

        return $payment->load(['patient', 'invoice', 'emiPlan', 'creator', 'allocations.installment']);
    }
}
