@extends('layouts.app')
@php $editing = $appointment->exists; @endphp
@section('title', $editing ? 'Change appointment' : 'Book appointment')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('appointments.index') }}">Appointments</a></li>
    <li class="breadcrumb-item active">{{ $editing ? $appointment->booking_number : 'Book' }}</li>
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
<form method="POST" action="{{ $editing ? route('appointments.update', $appointment) : route('appointments.store') }}" class="card" style="max-width:820px" novalidate data-loading-form>
    @csrf
    @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-7">
            <label for="patient_id" class="form-label">Patient <span class="text-danger">*</span></label>
            @if ($editing || $patient)
                @unless ($editing)<input type="hidden" name="patient_id" value="{{ $patient->id }}">@endunless
                <input class="form-control" disabled value="{{ $patient->name }} · {{ $patient->phone }} · {{ $patient->uhid }}">
            @else
                <select name="patient_id" id="patient_id" class="form-select js-patient-select @error('patient_id') is-invalid @enderror" required data-url="{{ route('patients.search') }}">
                    @php $sel = old('patient_id') ? \App\Models\Patient::find(old('patient_id')) : null; @endphp
                    @if ($sel)<option value="{{ $sel->id }}" selected>{{ $sel->name }} · {{ $sel->phone }} · {{ $sel->uhid }}</option>@endif
                </select>
                <div class="invalid-feedback">{{ $errors->first('patient_id') ?: 'Please select a patient.' }}</div>
                <div class="form-text">New patient? Register them first, then book from their page.</div>
            @endif
        </div>
        <div class="col-md-5">
            @include('partials.select', ['name' => 'doctor_id', 'label' => 'Doctor', 'required' => true, 'options' => $doctors->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->specialization ? ' — '.$d->specialization : '')]), 'value' => $appointment->doctor_id, 'placeholder' => 'Select doctor…'])
        </div>
        <div class="col-md-4">@include('partials.field', ['name' => 'appointment_date', 'label' => 'Date', 'type' => 'date', 'value' => $appointment->appointment_date?->toDateString() ?? today()->toDateString(), 'required' => true, 'msg' => 'Choose today or a later date.'])</div>
        <div class="col-md-4">@include('partials.field', ['name' => 'appointment_time', 'label' => 'Time', 'type' => 'time', 'value' => $appointment->appointment_time ? substr($appointment->appointment_time, 0, 5) : '', 'required' => true, 'msg' => 'Choose the time.'])</div>
        <div class="col-md-4">
            <label for="duration_minutes" class="form-label">Slot length</label>
            <select name="duration_minutes" id="duration_minutes" class="form-select">
                @foreach (\App\Http\Requests\Appointments\StoreAppointmentRequest::DURATIONS as $m)
                    <option value="{{ $m }}" @selected((int) old('duration_minutes', $appointment->duration_minutes ?: 15) === $m)>{{ $m }} minutes</option>
                @endforeach
            </select>
        </div>
        <div class="col-12">@include('partials.field', ['name' => 'reason', 'label' => 'Reason (optional)', 'value' => $appointment->reason, 'maxlength' => 255, 'placeholder' => 'e.g. Tooth pain, check-up, braces adjustment'])</div>

        @if ($errors->has('appointment_time') && str_contains($errors->first('appointment_time'), 'already has') || old('allow_overlap'))
            <div class="col-12"><div class="form-check p-3 border rounded bg-warning-subtle">
                <input type="checkbox" class="form-check-input ms-0 me-2" name="allow_overlap" id="allow_overlap" value="1" @checked(old('allow_overlap'))>
                <label class="form-check-label" for="allow_overlap"><strong>Book anyway</strong> — I know the doctor has another patient at this time.</label>
            </div></div>
        @endif
        @if ($editing && $appointment->status === \App\Enums\AppointmentStatus::Confirmed)
            <div class="col-12"><div class="alert alert-info mb-0 small"><i class="bi bi-info-circle me-1"></i>This appointment is confirmed. If you change the day, time or doctor, the patient will need to confirm again.</div></div>
        @endif
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ $editing ? route('appointments.show', $appointment) : route('appointments.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary px-4">{{ $editing ? 'Save changes' : 'Book appointment' }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>$('#appointment_date').attr('min', new Date().toISOString().slice(0, 10));</script>
@endpush
