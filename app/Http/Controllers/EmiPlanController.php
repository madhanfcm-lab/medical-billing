<?php

namespace App\Http\Controllers;

use App\Enums\EmiPlanStatus;
use App\Enums\PaymentMode;
use App\Http\Requests\Emi\PreviewEmiRequest;
use App\Http\Requests\Emi\StoreEmiPlanRequest;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\EmiScheduleGenerator;
use App\Services\EmiService;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EmiPlanController extends Controller
{
    public function __construct(private readonly EmiService $emi, private readonly EmiScheduleGenerator $generator) {}

    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $user = $request->user();
            $query = EmiPlan::query()->with('patient:id,uhid,name,phone')->select('emi_plans.*')
                ->withMin(['installments as next_due' => fn ($q) => $q->unpaid()], 'due_date')
                ->when($user->hasRole('doctor'), fn ($q) => $q->whereIn('patient_id', Patient::visibleTo($user)->select('id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

            return DataTables::eloquent($query)
                ->editColumn('plan_number', fn (EmiPlan $p) => '<a href="'.route('emi-plans.show', $p).'" class="fw-semibold">'.e($p->plan_number).'</a>')
                ->addColumn('patient', fn (EmiPlan $p) => '<a href="'.route('patients.show', $p->patient_id).'">'.e($p->patient->name).'</a><div class="small text-muted">'.e($p->patient->phone).'</div>')
                ->editColumn('treatment_name', fn (EmiPlan $p) => e($p->treatment_name))
                ->editColumn('total_amount', fn (EmiPlan $p) => money($p->total_amount))
                ->addColumn('paid', fn (EmiPlan $p) => money(to_paise($p->down_payment) / 100 + (float) $p->paid_amount))
                ->editColumn('balance_amount', fn (EmiPlan $p) => money($p->balance_amount))
                ->addColumn('next_due_fmt', fn (EmiPlan $p) => $p->status->isOpen() && $p->next_due ? format_date($p->next_due) : '—')
                ->editColumn('end_date', fn (EmiPlan $p) => format_date($p->end_date))
                ->editColumn('status', fn (EmiPlan $p) => view('emi._status', ['status' => $p->status])->render())
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($pt) => $pt->search($kw)))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->orderColumn('paid', fn ($q, $dir) => $q->orderBy('paid_amount', $dir))
                ->orderColumn('next_due_fmt', fn ($q, $dir) => $q->orderBy('next_due', $dir))
                ->rawColumns(['plan_number', 'patient', 'status'])
                ->toJson();
        }

        return view('emi.index', ['statuses' => EmiPlanStatus::cases()]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $invoice = $request->filled('invoice') ? Invoice::with('patient')->find($request->integer('invoice')) : null;
        $visit = $request->filled('visit') ? Visit::with('patient')->find($request->integer('visit')) : null;
        $patient = $invoice?->patient ?? $visit?->patient
            ?? ($request->filled('patient') ? Patient::query()->visibleTo($user)->find($request->integer('patient')) : null);

        abort_if($invoice && ($invoice->isCancelled() || $invoice->hasOpenEmiPlan() || to_paise($invoice->balance_amount) <= 0), 422, 'This invoice cannot be put on an EMI plan.');

        return view('emi.create', [
            'patient' => $patient,
            'visit' => $visit,
            'invoice' => $invoice,
            'treatment' => $invoice ? 'Invoice '.$invoice->invoice_number : ($visit?->treatment_given ?: ''),
            'total' => $invoice?->balance_amount,
            'modes' => collect(PaymentMode::cases())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label(), 'ref' => $m->requiresReference()]),
            'isAdmin' => $user->hasRole('admin'),
        ]);
    }

    /** Schedule preview for the form (nothing is saved). */
    public function preview(PreviewEmiRequest $request): JsonResponse
    {
        try {
            $s = $this->generator->generate(
                $request->input('amount'), $request->input('calc_by'), $request->input('months'), $request->input('monthly_amount'),
                Carbon::parse($request->input('start_date')), (int) $request->input('due_day'), (int) $request->input('first_number', 1),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['schedule' => [$e->getMessage()]]], 422);
        }

        return response()->json([
            'months' => $s['months'],
            'monthly_amount' => $s['monthly_amount'],
            'monthly_amount_fmt' => money($s['monthly_amount']),
            'first_due_date' => format_date($s['first_due_date']),
            'end_date' => format_date($s['end_date']),
            'installments' => collect($s['installments'])->map(fn ($i) => [
                'no' => $i['no'], 'due_date' => format_date($i['due_date']), 'amount' => $i['amount'], 'amount_fmt' => money($i['amount']),
            ])->all(),
        ]);
    }

    public function store(StoreEmiPlanRequest $request): RedirectResponse
    {
        $plan = $this->emi->create($request->validated());

        return redirect()->route('emi-plans.show', $plan)->with('success', "EMI plan {$plan->plan_number} created.");
    }

    public function show(EmiPlan $emiPlan): View
    {
        $this->authorize('view', $emiPlan);

        $emiPlan->load([
            'patient', 'invoice', 'visit', 'cancelledBy', 'reschedules.creator',
            'installments',
            'payments' => fn ($q) => $q->orderBy('id')->with(['allocations.installment', 'creator']),
        ]);

        return view('emi.show', [
            'plan' => $emiPlan,
            'modes' => PaymentMode::options(),
            'openInstallments' => $emiPlan->installments->filter(fn ($i) => to_paise($i->balance) > 0)->values()
                ->map(fn ($i) => ['no' => $i->installment_no, 'balance' => (float) $i->balance]),
            'projectedEnd' => $emiPlan->projectedEndDate(),
            'audit' => request()->user()->can('audit.view') ? $emiPlan->activities()->with('user:id,name')->get() : null,
        ]);
    }

    public function dues(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $user = $request->user();
            $overdue = $request->input('type') === 'overdue';

            $query = EmiInstallment::query()->with('plan.patient:id,uhid,name,phone')->select('emi_installments.*')
                ->when($overdue, fn ($q) => $q->overdue(), fn ($q) => $q->upcoming(7))
                ->when($user->hasRole('doctor'), fn ($q) => $q->whereHas('plan', fn ($p) => $p->whereIn('patient_id', Patient::visibleTo($user)->select('id'))));

            return DataTables::eloquent($query)
                ->addColumn('patient', fn (EmiInstallment $i) => '<a href="'.route('patients.show', $i->plan->patient_id).'">'.e($i->plan->patient->name).'</a><div class="small text-muted">'.e($i->plan->patient->uhid).'</div>')
                ->addColumn('phone', fn (EmiInstallment $i) => e($i->plan->patient->phone))
                ->addColumn('plan', fn (EmiInstallment $i) => '<a href="'.route('emi-plans.show', $i->emi_plan_id).'">'.e($i->plan->plan_number).'</a><div class="small text-muted">'.e($i->plan->treatment_name).'</div>')
                ->editColumn('installment_no', fn (EmiInstallment $i) => '#'.$i->installment_no)
                ->editColumn('due_date', fn (EmiInstallment $i) => format_date($i->due_date))
                ->addColumn('balance', fn (EmiInstallment $i) => money($i->balance))
                ->addColumn('days', fn (EmiInstallment $i) => $overdue
                    ? '<span class="text-danger fw-semibold">'.(int) $i->due_date->diffInDays(today(), true).' days overdue</span>'
                    : ($i->due_date->isToday() ? 'Due today' : 'In '.(int) today()->diffInDays($i->due_date, true).' days'))
                ->addColumn('actions', fn (EmiInstallment $i) => view('emi._due-actions', [
                    'installment' => $i, 'link' => Whatsapp::link($i->plan->patient->phone, Whatsapp::emiReminder($i)),
                ])->render())
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('plan.patient', fn ($p) => $p->search($kw)))
                ->filterColumn('plan', fn ($q, $kw) => $q->whereHas('plan', fn ($p) => $p->where('plan_number', 'like', "%{$kw}%")->orWhere('treatment_name', 'like', "%{$kw}%")))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('emi_plan_id', $dir))
                ->orderColumn('plan', fn ($q, $dir) => $q->orderBy('emi_plan_id', $dir))
                ->orderColumn('balance', fn ($q, $dir) => $q->orderByRaw('(amount - paid_amount) '.($dir === 'desc' ? 'desc' : 'asc')))
                ->orderColumn('days', fn ($q, $dir) => $q->orderBy('due_date', $dir === 'desc' ? 'asc' : 'desc'))
                ->filter(function ($q) use ($request) {
                    if ($kw = trim((string) $request->input('search.value'))) {
                        $q->where(fn ($w) => $w
                            ->whereHas('plan.patient', fn ($p) => $p->search($kw))
                            ->orWhereHas('plan', fn ($p) => $p->where('plan_number', 'like', "%{$kw}%")->orWhere('treatment_name', 'like', "%{$kw}%")));
                    }
                }, true)
                ->rawColumns(['patient', 'plan', 'days', 'actions'])
                ->toJson();
        }

        return view('emi.dues');
    }
}
