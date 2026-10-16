<?php

namespace App\Http\Controllers;

use App\Http\Requests\Visits\VisitRequest;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\FollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class VisitController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $query = Visit::query()->with(['patient:id,uhid,name', 'doctor:id,name'])->select('visits.*')
                ->when($request->user()->hasRole('doctor'), fn ($q) => $q->where('doctor_id', $request->user()->doctorProfile?->id ?? 0))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('visit_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('visit_date', '<=', $request->input('to')))
                ->when($request->filled('doctor_id'), fn ($q) => $q->where('doctor_id', $request->integer('doctor_id')));

            return DataTables::eloquent($query)
                ->editColumn('visit_date', fn (Visit $v) => format_date($v->visit_date))
                ->addColumn('patient', fn (Visit $v) => '<a href="'.route('patients.show', $v->patient_id).'">'.e($v->patient->name).'</a><div class="small text-muted">'.e($v->patient->uhid).'</div>')
                ->addColumn('doctor', fn (Visit $v) => e($v->doctor->name))
                ->editColumn('chief_complaint', fn (Visit $v) => e(Str::limit((string) $v->chief_complaint, 60)))
                ->addColumn('actions', fn (Visit $v) => '<a href="'.route('visits.show', $v).'" class="btn btn-sm btn-outline-secondary" title="Open"><i class="bi bi-eye"></i></a>')
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($p) => $p->search($kw)))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->rawColumns(['patient', 'actions'])
                ->toJson();
        }

        return view('visits.index', ['doctors' => $this->doctors()]);
    }

    public function create(Request $request): View
    {
        $patient = $request->filled('patient')
            ? Patient::query()->visibleTo($request->user())->find($request->integer('patient'))
            : null;

        // "Complete & record visit" from a follow-up: prefill, and link the visit when it is saved.
        $followUp = $request->filled('follow_up')
            ? FollowUp::query()->visibleTo($request->user())->with('patient')->find($request->integer('follow_up'))
            : null;
        $patient ??= $followUp?->patient;

        return view('visits.form', [
            'visit' => new Visit([
                'visit_date' => now(), 'patient_id' => $patient?->id,
                'doctor_id' => $followUp?->doctor_id ?? $request->user()->doctorProfile?->id,
                'chief_complaint' => $followUp ? 'Follow-up: '.$followUp->reason : null,
            ]),
            'patient' => $patient,
            'followUp' => $followUp,
            'doctors' => $this->doctors(),
        ]);
    }

    public function store(VisitRequest $request, FollowUpService $followUps): RedirectResponse
    {
        $visit = Visit::create($request->safe()->except('follow_up_id'));

        if ($request->filled('follow_up_id') && $request->user()->can('followups.manage')) {
            $followUps->completeWithVisit(FollowUp::findOrFail($request->input('follow_up_id')), $visit);
        }

        return redirect()->route('visits.show', $visit)->with('success', 'Visit recorded.');
    }

    public function show(Visit $visit): View
    {
        $this->authorize('view', $visit);

        return view('visits.show', ['visit' => $visit->load(['patient', 'doctor', 'invoices'])]);
    }

    public function edit(Visit $visit): View
    {
        $this->authorize('update', $visit);

        return view('visits.form', ['visit' => $visit, 'patient' => $visit->patient, 'followUp' => null, 'doctors' => $this->doctors()]);
    }

    public function update(VisitRequest $request, Visit $visit): RedirectResponse
    {
        $this->authorize('update', $visit);
        $visit->update($request->validated());

        return redirect()->route('visits.show', $visit)->with('success', 'Visit updated.');
    }

    private function doctors()
    {
        return Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization']);
    }
}
