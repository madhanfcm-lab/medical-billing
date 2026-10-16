@extends('layouts.app')
@section('title', 'Appointment '.$appointment->booking_number)
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('appointments.index') }}">Appointments</a></li>
    <li class="breadcrumb-item active">{{ $appointment->booking_number }}</li>
@endsection
@section('actions')
    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
    <a href="{{ route('appointments.slip', $appointment) }}" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print slip</a>
    @can('manage', $appointment)
        @if ($appointment->appointment_date->isToday())
            <button class="btn btn-primary js-action" data-url="{{ route('appointments.check-in', $appointment) }}" data-method="POST" data-title="Check in {{ $appointment->patient->name }}?" data-text="This opens today's visit with {{ $appointment->doctor->name }}." data-confirm="Yes, check in"><i class="bi bi-box-arrow-in-right me-1"></i>Patient arrived</button>
        @endif
        <a href="{{ route('appointments.edit', $appointment) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Change</a>
        <button class="btn btn-outline-danger js-cancel-appt" data-url="{{ route('appointments.cancel', $appointment) }}" data-name="{{ $appointment->patient->name }}"><i class="bi bi-x-circle me-1"></i>Cancel</button>
    @endcan
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($appointment->isOverdue())
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2"><span><i class="bi bi-exclamation-triangle me-1"></i>This appointment was on {{ format_date($appointment->appointment_date) }} and the patient did not arrive.</span>
        @can('manage', $appointment)<button class="btn btn-sm btn-outline-dark js-action" data-url="{{ route('appointments.no-show', $appointment) }}" data-method="POST" data-title="Mark as no-show?" data-text="{{ $appointment->patient->name }} did not come." data-confirm="Yes, no-show">Mark as no-show</button>@endcan</div>
@endif
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Details</span> @include('appointments._status', ['appointment' => $appointment])</div><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-sm-4">Patient</dt><dd class="col-sm-8"><a href="{{ route('patients.show', $appointment->patient_id) }}">{{ $appointment->patient->name }}</a> · {{ $appointment->patient->phone }}</dd>
                <dt class="col-sm-4">When</dt><dd class="col-sm-8">{{ $appointment->appointment_date->format('l, d-m-Y') }} · {{ $appointment->time_label }} <span class="text-muted">({{ $appointment->duration_minutes }} min)</span></dd>
                <dt class="col-sm-4">Doctor</dt><dd class="col-sm-8">{{ $appointment->doctor->name }}</dd>
                <dt class="col-sm-4">Branch</dt><dd class="col-sm-8">{{ $appointment->branch->name }}</dd>
                <dt class="col-sm-4">Reason</dt><dd class="col-sm-8">{{ $appointment->reason ?: '—' }}</dd>
                @if ($appointment->visit)<dt class="col-sm-4">Visit</dt><dd class="col-sm-8"><a href="{{ route('visits.show', $appointment->visit) }}">Opened on {{ format_date($appointment->visit->visit_date) }}</a></dd>@endif
                @if ($appointment->cancel_reason)<dt class="col-sm-4">Cancel reason</dt><dd class="col-sm-8">{{ $appointment->cancel_reason }}</dd>@endif
                <dt class="col-sm-4">Booked</dt><dd class="col-sm-8">{{ format_datetime($appointment->created_at) }} by {{ $appointment->creator?->name ?? '—' }}</dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-header">Confirmation</div><div class="card-body">
            @if ($appointment->status === \App\Enums\AppointmentStatus::Confirmed)
                <div class="d-flex gap-2 align-items-start"><i class="bi bi-patch-check-fill text-success fs-4"></i><div>
                    <div class="fw-semibold">Confirmed by the patient</div>
                    <div class="small text-muted">{{ $appointment->confirmed_via?->label() }} · {{ format_datetime($appointment->confirmed_at) }} · recorded by {{ $appointment->confirmer?->name ?? '—' }}</div>
                    @if ($appointment->confirmation_note)<div class="small mt-1">“{{ $appointment->confirmation_note }}”</div>@endif
                </div></div>
            @elseif ($appointment->status === \App\Enums\AppointmentStatus::Scheduled)
                <p class="text-muted small">Ring or message the patient and ask them to confirm. The WhatsApp button has the message ready.</p>
                @can('manage', $appointment)
                    <button class="btn btn-success js-confirm-appt" data-url="{{ route('appointments.confirm', $appointment) }}" data-name="{{ $appointment->patient->name }}"><i class="bi bi-check2-circle me-1"></i>Patient confirmed</button>
                @endcan
            @else
                <p class="text-muted mb-0">{{ $appointment->status->label() }}.</p>
            @endif
        </div></div>
    </div>
</div>
@include('appointments._modals')
@endsection
