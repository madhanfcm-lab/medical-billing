@extends('layouts.app')
@php
    $editing = $patient->exists;
    // Optional details start folded away on a new registration unless something is already filled in or went wrong.
    $moreFilled = $editing || collect(['alternate_phone', 'email', 'address', 'blood_group', 'emergency_contact_name', 'emergency_contact_phone'])
        ->contains(fn ($f) => filled(old($f, $patient->$f)) || $errors->has($f));
@endphp
@section('title', $editing ? 'Edit patient' : 'Register patient')
@section('subtitle', $editing ? $patient->uhid : 'Only name, gender and mobile number are needed to start. You can add the rest later.')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('patients.index') }}">Patients</a></li>
    @if ($editing)<li class="breadcrumb-item"><a href="{{ route('patients.show', $patient) }}">{{ $patient->uhid }}</a></li>@endif
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'Register' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('patients.update', $patient) : route('patients.store') }}" novalidate data-loading-form style="max-width:900px">
    @csrf @if ($editing) @method('PUT') @endif

    <div class="card mb-3">
        <div class="card-header">The essentials</div>
        <div class="card-body row g-3">
            <div class="col-md-6">@include('partials.field', ['name' => 'name', 'label' => 'Full name', 'value' => $patient->name, 'required' => true, 'maxlength' => 150, 'autocomplete' => 'off'])</div>
            <div class="col-md-3">@include('partials.select', ['name' => 'gender', 'label' => 'Gender', 'required' => true, 'options' => collect(\App\Enums\Gender::cases())->mapWithKeys(fn ($g) => [$g->value => $g->label()]), 'value' => $patient->gender?->value, 'placeholder' => 'Select…'])</div>
            <div class="col-md-3">@include('partials.field', ['name' => 'phone', 'label' => 'Mobile', 'value' => $patient->phone, 'required' => true, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number (starts with 6-9).', 'autocomplete' => 'off', 'placeholder' => '10 digits'])</div>
            <div class="col-md-4">
                @include('partials.field', ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => 'date', 'value' => $patient->date_of_birth?->format('Y-m-d'), 'msg' => 'Date of birth cannot be in the future.'])
            </div>
            <div class="col-md-3">
                <label class="form-label" for="age_years">…or age (years)</label>
                <input type="number" id="age_years" min="0" max="120" class="form-control" placeholder="e.g. 34">
                <div class="form-text">Age now: <strong id="ageOut">—</strong></div>
            </div>
            <div class="col-md-5 d-none d-md-block"></div>
            <div class="col-12">
                <label for="allergies" class="form-label">Allergies / medical notes <span class="text-muted fw-normal">(shown as a warning on their page)</span></label>
                <textarea name="allergies" id="allergies" rows="2" maxlength="1000" class="form-control @error('allergies') is-invalid @enderror" placeholder="e.g. Allergic to penicillin, diabetic, on blood thinners">{{ old('allergies', $patient->allergies) }}</textarea>
                <div class="invalid-feedback">{{ $errors->first('allergies') }}</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <button type="button" class="card-header w-100 text-start border-0 d-flex justify-content-between align-items-center" data-bs-toggle="collapse" data-bs-target="#moreDetails" aria-expanded="{{ $moreFilled ? 'true' : 'false' }}">
            <span>More details <span class="text-muted fw-normal">· optional</span></span><i class="bi bi-chevron-down text-muted"></i>
        </button>
        <div id="moreDetails" class="collapse{{ $moreFilled ? ' show' : '' }}">
            <div class="card-body row g-3 border-top">
                <div class="col-md-4">@include('partials.field', ['name' => 'alternate_phone', 'label' => 'Alternate phone', 'value' => $patient->alternate_phone, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number.'])</div>
                <div class="col-md-5">@include('partials.field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $patient->email, 'maxlength' => 150, 'msg' => 'Enter a valid email address.'])</div>
                <div class="col-md-3">@include('partials.select', ['name' => 'blood_group', 'label' => 'Blood group', 'options' => collect(\App\Enums\BloodGroup::cases())->mapWithKeys(fn ($b) => [$b->value => $b->value]), 'value' => $patient->blood_group?->value, 'placeholder' => 'Unknown'])</div>
                <div class="col-12">
                    <label for="address" class="form-label">Address</label>
                    <textarea name="address" id="address" rows="2" maxlength="1000" class="form-control @error('address') is-invalid @enderror">{{ old('address', $patient->address) }}</textarea>
                    <div class="invalid-feedback">{{ $errors->first('address') }}</div>
                </div>
                <div class="col-md-7">@include('partials.field', ['name' => 'emergency_contact_name', 'label' => 'Emergency contact: name', 'value' => $patient->emergency_contact_name, 'maxlength' => 150])</div>
                <div class="col-md-5">@include('partials.field', ['name' => 'emergency_contact_phone', 'label' => 'Emergency contact: phone', 'value' => $patient->emergency_contact_phone, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number.'])</div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="{{ $editing ? route('patients.show', $patient) : route('patients.index') }}" class="btn btn-light">Cancel</a>
        <div class="d-flex gap-2">
            @if ($editing)
                <button class="btn btn-primary px-4">Save changes</button>
            @else
                <button class="btn btn-outline-primary px-3">Register only</button>
                @can('invoices.create')<button class="btn btn-primary px-4" name="after" value="bill"><i class="bi bi-receipt me-1"></i>Register &amp; start bill</button>@endcan
            @endif
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
const $dob = $('#date_of_birth').attr('max', new Date().toISOString().slice(0, 10));
const ymd = d => d.toISOString().slice(0, 10);

function ageFromDob(v) {
    if (!v) return null;
    const d = new Date(v), n = new Date();
    let y = n.getFullYear() - d.getFullYear();
    if (n.getMonth() < d.getMonth() || (n.getMonth() === d.getMonth() && n.getDate() < d.getDate())) y--;
    if (y >= 1) return y + ' yrs';
    const m = (n.getFullYear() - d.getFullYear()) * 12 + n.getMonth() - d.getMonth() - (n.getDate() < d.getDate() ? 1 : 0);
    return m >= 1 ? m + ' mths' : Math.max(0, Math.floor((n - d) / 864e5)) + ' days';
}
function showAge() {
    const a = ageFromDob($dob.val());
    $('#ageOut').text(a || '—');
    const years = parseInt(a, 10);
    if (a && /yrs/.test(a) && document.activeElement !== document.getElementById('age_years')) $('#age_years').val(years);
}
// Typing an age fills an approximate date of birth (same day and month as today, that many years ago).
$('#age_years').on('input', function () {
    const n = parseInt(this.value, 10);
    if (isNaN(n) || n < 0 || n > 120) return;
    const d = new Date(); d.setFullYear(d.getFullYear() - n);
    $dob.val(ymd(d)); $('#ageOut').text(n + ' yrs');
});
$dob.on('input change', showAge); showAge();

// digits only in phone boxes
$('#phone, #alternate_phone, #emergency_contact_phone').on('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); });
</script>
@endpush
