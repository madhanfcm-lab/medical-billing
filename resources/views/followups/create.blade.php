@extends('layouts.app')
@section('title', 'New follow-up')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('follow-ups.index') }}">Follow-ups</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
<form method="POST" action="{{ route('follow-ups.store') }}" class="card" style="max-width:820px" novalidate data-loading-form>
    @csrf
    <div class="card-body row g-3">
        <div class="col-md-7">
            <label for="patient_id" class="form-label">Patient <span class="text-danger">*</span></label>
            @if ($visit || $plan)
                <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                <input class="form-control" disabled value="{{ $patient->name }} · {{ $patient->phone }} · {{ $patient->uhid }}">
                @if ($visit)<input type="hidden" name="visit_id" value="{{ $visit->id }}"><div class="form-text">From the visit on {{ format_date($visit->visit_date) }}.</div>@endif
                @if ($plan)<input type="hidden" name="emi_plan_id" value="{{ $plan->id }}"><div class="form-text">About EMI plan {{ $plan->plan_number }}.</div>@endif
            @else
                <select name="patient_id" id="patient_id" class="form-select js-patient-select @error('patient_id') is-invalid @enderror" required data-url="{{ route('patients.search') }}">
                    @php $sel = $patient ?? (old('patient_id') ? \App\Models\Patient::find(old('patient_id')) : null); @endphp
                    @if ($sel)<option value="{{ $sel->id }}" selected>{{ $sel->name }} · {{ $sel->phone }} · {{ $sel->uhid }}</option>@endif
                </select>
                <div class="invalid-feedback">{{ $errors->first('patient_id') ?: 'Please select a patient.' }}</div>
            @endif
        </div>
        <div class="col-md-5">
            @if (auth()->user()->hasRole('doctor'))
                <label class="form-label">Doctor</label>
                <input class="form-control" disabled value="{{ auth()->user()->doctorProfile?->name ?? 'No doctor profile linked' }}">
            @else
                @include('partials.select', ['name' => 'doctor_id', 'label' => 'Doctor', 'required' => true, 'options' => $doctors->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->specialization ? ' — '.$d->specialization : '')]), 'value' => $doctorId, 'placeholder' => 'Select doctor…'])
            @endif
        </div>
        <div class="col-md-4">@include('partials.field', ['name' => 'follow_up_date', 'label' => 'Date', 'type' => 'date', 'value' => old('follow_up_date', today()->addWeek()->toDateString()), 'required' => true, 'msg' => 'Choose today or a later date.'])</div>
        <div class="col-md-3">@include('partials.field', ['name' => 'follow_up_time', 'label' => 'Time (optional)', 'type' => 'time', 'value' => old('follow_up_time')])</div>
        <div class="col-md-5">@include('partials.field', ['name' => 'reason', 'label' => 'Reason / purpose', 'value' => old('reason', $reason), 'required' => true, 'maxlength' => 200, 'placeholder' => 'e.g. Wire adjustment, suture removal'])</div>
        <div class="col-12">
            <label class="form-label" for="notes">Notes</label>
            <textarea name="notes" id="notes" rows="3" maxlength="2000" class="form-control @error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ url()->previous() }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary px-4">Schedule follow-up</button>
    </div>
</form>
@endsection

@push('scripts')
<script>$('#follow_up_date').attr('min', new Date().toISOString().slice(0, 10));</script>
@endpush
