<?php

namespace App\Http\Controllers;

use App\Enums\FollowUpStatus;
use App\Http\Requests\FollowUps\CancelFollowUpRequest;
use App\Http\Requests\FollowUps\RescheduleFollowUpRequest;
use App\Http\Requests\FollowUps\StoreFollowUpRequest;
use App\Models\Doctor;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\FollowUpService;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class FollowUpController extends Controller
{
    public const TABS = ['today', 'upcoming', 'missed', 'all'];

    public function __construct(private readonly FollowUpService $followUps) {}

    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();

        if ($request->ajax()) {
            $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'today';

            $query = FollowUp::query()->with(['patient:id,uhid,name,phone', 'doctor:id,name', 'visit:id', 'emiPlan:id,plan_number', 'rescheduledTo:id,rescheduled_from_id,follow_up_date'])
                ->select('follow_ups.*')
                ->visibleTo($user)
                ->when($tab === 'today', fn ($q) => $q->whereDate('follow_up_date', today()->toDateString())->whereIn('status', ['scheduled', 'completed']))
                ->when($tab === 'upcoming', fn ($q) => $q->upcoming())
                ->when($tab === 'missed', fn ($q) => $q->missed())
                ->when($tab === 'all' && $request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('doctor_id'), fn ($q) => $q->where('doctor_id', $request->integer('doctor_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('follow_up_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('follow_up_date', '<=', $request->input('to')));

            return DataTables::eloquent($query)
                ->editColumn('follow_up_date', fn (FollowUp $f) => '<a href="'.route('follow-ups.show', $f).'" class="fw-semibold">'.format_date($f->follow_up_date).'</a>'
                    .($f->time_label ? '<div class="small text-muted">'.$f->time_label.'</div>' : ''))
                ->addColumn('patient', fn (FollowUp $f) => '<a href="'.route('patients.show', $f->patient_id).'">'.e($f->patient->name).'</a><div class="small text-muted">'.e($f->patient->phone).'</div>')
                ->addColumn('doctor', fn (FollowUp $f) => e($f->doctor?->name ?? '—'))
                ->editColumn('reason', fn (FollowUp $f) => e($f->reason).($f->emiPlan ? '<div class="small text-muted"><i class="bi bi-calendar2-check"></i> '.e($f->emiPlan->plan_number).'</div>' : ''))
                ->editColumn('status', fn (FollowUp $f) => view('followups._status', ['followUp' => $f])->render())
                ->addColumn('actions', fn (FollowUp $f) => view('followups._actions', ['followUp' => $f, 'link' => Whatsapp::link($f->patient->phone, Whatsapp::followUpReminder($f))])->render())
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($p) => $p->search($kw)))
                ->filterColumn('reason', fn ($q, $kw) => $q->where('reason', 'like', "%{$kw}%"))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->orderColumn('doctor', fn ($q, $dir) => $q->orderBy('doctor_id', $dir))
                ->rawColumns(['follow_up_date', 'patient', 'reason', 'status', 'actions'])
                ->toJson();
        }

        $visible = fn () => FollowUp::query()->visibleTo($user);

        return view('followups.index', [
            'tab' => in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'today',
            'counts' => ['today' => $visible()->today()->count(), 'upcoming' => $visible()->upcoming()->count(), 'missed' => $visible()->missed()->count()],
            'doctors' => $user->hasRole('doctor') ? collect() : Doctor::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => FollowUpStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $visit = $request->filled('visit') ? Visit::with('patient')->find($request->integer('visit')) : null;
        $plan = $request->filled('emi_plan') ? EmiPlan::with('patient')->find($request->integer('emi_plan')) : null;
        $patient = $visit?->patient ?? $plan?->patient
            ?? ($request->filled('patient') ? Patient::query()->visibleTo($user)->find($request->integer('patient')) : null);

        return view('followups.create', [
            'patient' => $patient,
            'visit' => $visit,
            'plan' => $plan,
            'doctorId' => $visit?->doctor_id ?? $user->doctorProfile?->id,
            'reason' => $plan ? 'EMI payment follow-up — '.$plan->treatment_name : '',
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization']),
        ]);
    }

    public function store(StoreFollowUpRequest $request): RedirectResponse
    {
        $followUp = $this->followUps->create($request->validated());

        return redirect()->route('follow-ups.show', $followUp)->with('success', 'Follow-up scheduled for '.format_date($followUp->follow_up_date).'.');
    }

    public function show(FollowUp $followUp): View
    {
        $this->authorize('view', $followUp);

        return view('followups.show', [
            'followUp' => $followUp->load(['patient', 'doctor', 'visit', 'emiPlan', 'completedVisit', 'rescheduledFrom', 'rescheduledTo', 'creator']),
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name']),
            'whatsapp' => Whatsapp::link($followUp->patient->phone, Whatsapp::followUpReminder($followUp)),
        ]);
    }

    public function complete(FollowUp $followUp): RedirectResponse
    {
        $this->authorize('manage', $followUp);
        $this->followUps->complete($followUp);

        return back()->with('success', 'Follow-up marked as completed.');
    }

    public function reschedule(RescheduleFollowUpRequest $request, FollowUp $followUp): RedirectResponse
    {
        $new = $this->followUps->reschedule($followUp, $request->validated());

        return redirect()->route('follow-ups.show', $new)->with('success', 'Rescheduled to '.format_date($new->follow_up_date).'. The earlier follow-up is kept in the history.');
    }

    public function cancel(CancelFollowUpRequest $request, FollowUp $followUp): RedirectResponse
    {
        $this->followUps->cancel($followUp, $request->validated('reason'));

        return back()->with('success', 'Follow-up cancelled.');
    }
}
