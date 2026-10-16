@extends('layouts.app')
@php $editing = $visit->exists; $isDoctor = auth()->user()->hasRole('doctor'); @endphp
@section('title', $editing ? 'Edit visit' : 'New visit')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('visits.index') }}">Visits</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('visits.update', $visit) : route('visits.store') }}" class="card" style="max-width:900px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    @if (! empty($followUp))
        <input type="hidden" name="follow_up_id" value="{{ $followUp->id }}">
        <div class="card-header bg-info-subtle"><i class="bi bi-telephone me-1"></i>Recording the visit for the follow-up on {{ format_date($followUp->follow_up_date) }} ({{ $followUp->reason }}). Saving completes the follow-up.</div>
    @endif
    <div class="card-body row g-3">
        <div class="col-md-6">
            <label for="patient_id" class="form-label">Patient <span class="text-danger">*</span></label>
            @if ($editing)
                <input type="hidden" name="patient_id" value="{{ $visit->patient_id }}">
                <input class="form-control" value="{{ $patient->name }} · {{ $patient->uhid }}" disabled>
            @else
                <select name="patient_id" id="patient_id" class="form-select js-patient-select @error('patient_id') is-invalid @enderror" required
                        data-url="{{ route('patients.search') }}">
                    @if (old('patient_id', $patient?->id))
                        @php $sel = $patient ?? \App\Models\Patient::find(old('patient_id')); @endphp
                        @if ($sel)<option value="{{ $sel->id }}" selected>{{ $sel->name }} · {{ $sel->phone }} · {{ $sel->uhid }}</option>@endif
                    @endif
                </select>
                <div class="invalid-feedback d-block">{{ $errors->first('patient_id') }}</div>
            @endif
        </div>
        <div class="col-md-4">
            @if ($isDoctor)
                <label class="form-label">Doctor</label>
                <input class="form-control" value="{{ auth()->user()->doctorProfile?->name ?? 'No doctor profile linked' }}" disabled>
                <div class="invalid-feedback d-block">{{ $errors->first('doctor_id') }}</div>
            @else
                @include('partials.select', ['name' => 'doctor_id', 'label' => 'Doctor', 'required' => true, 'options' => $doctors->mapWithKeys(fn ($d) => [$d->id => $d->name.($d->specialization ? ' — '.$d->specialization : '')]), 'value' => $visit->doctor_id, 'placeholder' => 'Select doctor…'])
            @endif
        </div>
        <div class="col-md-2">@include('partials.field', ['name' => 'visit_date', 'label' => 'Visit date', 'type' => 'date', 'value' => $visit->visit_date?->format('Y-m-d'), 'required' => true])</div>
        @foreach (['chief_complaint' => ['Chief complaint', true], 'diagnosis' => ['Diagnosis', false], 'treatment_given' => ['Treatment given', false], 'notes' => ['Notes', false]] as $f => [$label, $req])
            <div class="col-12 {{ $f === 'chief_complaint' || $f === 'notes' ? '' : 'col-md-6' }}">
                <label for="{{ $f }}" class="form-label">{{ $label }} @if ($req)<span class="text-danger">*</span>@endif</label>
                <textarea name="{{ $f }}" id="{{ $f }}" rows="{{ $f === 'notes' ? 3 : 2 }}" maxlength="4000" @required($req)
                          class="form-control @error($f) is-invalid @enderror">{{ old($f, $visit->$f) }}</textarea>
                <div class="invalid-feedback">{{ $errors->first($f) ?: 'This field is required.' }}</div>
            </div>
        @endforeach
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ $editing ? route('visits.show', $visit) : route('visits.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary px-4">{{ $editing ? 'Save changes' : 'Save visit' }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>$('#visit_date').attr('max', new Date().toISOString().slice(0, 10));</script>
@endpush
