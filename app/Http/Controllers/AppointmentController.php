<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\ConfirmationMode;
use App\Http\Requests\Appointments\CancelAppointmentRequest;
use App\Http\Requests\Appointments\ConfirmAppointmentRequest;
use App\Http\Requests\Appointments\StoreAppointmentRequest;
use App\Http\Requests\Appointments\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\AppointmentService;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AppointmentController extends Controller
{
    public const TABS = ['today', 'upcoming', 'confirm', 'all'];

    public function __construct(private readonly AppointmentService $appointments) {}

    public function index(Request $request): View|JsonResponse
    {
        $user = $request->user();
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'today';

        if ($request->ajax()) {
            $query = Appointment::query()->with(['patient:id,uhid,name,phone', 'doctor:id,name', 'confirmer:id,name'])
                ->select('appointments.*')->visibleTo($user)
                ->when($tab === 'today', fn ($q) => $q->onDate(today()->toDateString()))
                ->when($tab === 'upcoming', fn ($q) => $q->open()->whereDate('appointment_date', '>', today()->toDateString()))
                ->when($tab === 'confirm', fn ($q) => $q->needsConfirmation())
                ->when($tab === 'all' && $request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->when($request->filled('doctor_id'), fn ($q) => $q->where('doctor_id', $request->integer('doctor_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('appointment_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('appointment_date', '<=', $request->input('to')));

            return DataTables::eloquent($query)
                ->addColumn('when', fn (Appointment $a) => '<a href="'.route('appointments.show', $a).'" class="fw-semibold">'.format_date($a->appointment_date).'</a><div class="small text-muted">'.$a->time_label.'</div>')
                ->addColumn('patient', fn (Appointment $a) => '<a href="'.route('patients.show', $a->patient_id).'">'.e($a->patient->name).'</a><div class="small text-muted">'.e($a->patient->phone).'</div>')
                ->addColumn('doctor', fn (Appointment $a) => e($a->doctor->name))
                ->editColumn('reason', fn (Appointment $a) => e($a->reason ?: '—'))
                ->editColumn('status', fn (Appointment $a) => view('appointments._status', ['appointment' => $a])->render())
                ->addColumn('actions', fn (Appointment $a) => view('appointments._actions', ['appointment' => $a])->render())
                ->filterColumn('patient', fn ($q, $kw) => $q->whereHas('patient', fn ($p) => $p->search($kw)))
                ->filterColumn('reason', fn ($q, $kw) => $q->where('reason', 'like', "%{$kw}%"))
                ->orderColumn('when', fn ($q, $dir) => $q->orderBy('appointment_date', $dir)->orderBy('appointment_time', $dir))
                ->orderColumn('patient', fn ($q, $dir) => $q->orderBy('patient_id', $dir))
                ->rawColumns(['when', 'patient', 'status', 'actions'])
                ->toJson();
        }

        $visible = fn () => Appointment::query()->visibleTo($user);

        return view('appointments.index', [
            'tab' => $tab,
            'counts' => [
                'today' => $visible()->onDate(today()->toDateString())->open()->count(),
                'upcoming' => $visible()->open()->whereDate('appointment_date', '>', today()->toDateString())->count(),
                'confirm' => $visible()->needsConfirmation()->count(),
            ],
            'doctors' => $user->hasRole('doctor') ? collect() : Doctor::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => AppointmentStatus::cases(),
            'modes' => ConfirmationMode::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $patient = $request->filled('patient') ? Patient::query()->visibleTo($request->user())->find($request->integer('patient')) : null;

        return view('appointments.form', [
            'appointment' => new Appointment([
                'patient_id' => $patient?->id, 'appointment_date' => today(), 'appointment_time' => null, 'duration_minutes' => 15,
                'doctor_id' => $request->user()->doctorProfile?->id,
            ]),
            'patient' => $patient,
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization']),
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $appointment = $this->appointments->book($request->details(), $request->boolean('allow_overlap'));

        return redirect()->route('appointments.show', $appointment)
            ->with('success', 'Appointment booked for '.format_date($appointment->appointment_date).' at '.$appointment->time_label.'. It is waiting for the patient to confirm.');
    }

    public function show(Appointment $appointment): View
    {
        $this->authorize('view', $appointment);
        $appointment->load(['patient', 'doctor', 'visit', 'confirmer', 'creator', 'branch']);

        return view('appointments.show', [
            'appointment' => $appointment,
            'modes' => ConfirmationMode::cases(),
            'whatsapp' => Whatsapp::link($appointment->patient->phone, Whatsapp::appointment($appointment)),
        ]);
    }

    public function edit(Appointment $appointment): View
    {
        $this->authorize('manage', $appointment);

        return view('appointments.form', [
            'appointment' => $appointment->load('patient'),
            'patient' => $appointment->patient,
            'doctors' => Doctor::active()->orderBy('name')->get(['id', 'name', 'specialization']),
        ]);
    }

    public function update(UpdateAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $before = $appointment->status;
        $appointment = $this->appointments->update($appointment, $request->details(), $request->boolean('allow_overlap'));

        $note = $before === AppointmentStatus::Confirmed && $appointment->status === AppointmentStatus::Scheduled
            ? ' The time changed, so it needs to be confirmed with the patient again.' : '';

        return redirect()->route('appointments.show', $appointment)->with('success', 'Appointment updated.'.$note);
    }

    public function confirm(ConfirmAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->appointments->confirm($appointment, ConfirmationMode::from($request->validated('via')), $request->validated('note'));

        return back()->with('success', 'Appointment confirmed.');
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->appointments->cancel($appointment, $request->validated('reason'));

        return back()->with('success', 'Appointment cancelled.');
    }

    public function noShow(Appointment $appointment): RedirectResponse
    {
        $this->authorize('manage', $appointment);
        $this->appointments->markNoShow($appointment);

        return back()->with('success', 'Marked as no-show.');
    }

    /** The patient has arrived: open the visit and land on the patient's page, where the bill is made. */
    public function checkIn(Appointment $appointment): RedirectResponse
    {
        $this->authorize('manage', $appointment);
        $visit = $this->appointments->checkIn($appointment);

        return redirect()->route('patients.show', $visit->patient_id)
            ->with('success', $appointment->patient->name.' is checked in. The visit is open; you can make the bill from here.');
    }

    /** A printable confirmation slip the patient can take home. */
    public function slip(Appointment $appointment): View
    {
        $this->authorize('view', $appointment);

        return view('appointments.slip', ['appointment' => $appointment->load(['patient', 'doctor.department', 'branch', 'confirmer']), 'pdf' => false]);
    }
}
