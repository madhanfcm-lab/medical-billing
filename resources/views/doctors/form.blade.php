@extends('layouts.app')
@php $editing = $doctor->exists; @endphp
@section('title', $editing ? 'Edit doctor' : 'New doctor')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('doctors.index') }}">Doctors</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('doctors.update', $doctor) : route('doctors.store') }}" class="card" style="max-width:760px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-6">@include('partials.field', ['name' => 'name', 'label' => 'Doctor name', 'value' => $doctor->name, 'required' => true, 'maxlength' => 150])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'specialization', 'label' => 'Specialization', 'value' => $doctor->specialization, 'maxlength' => 150, 'placeholder' => 'e.g. Orthodontist'])</div>
        <div class="col-md-6">@include('partials.select', ['name' => 'department_id', 'label' => 'Department', 'options' => $departments->pluck('name', 'id'), 'value' => $doctor->department_id, 'placeholder' => '— None —'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'phone', 'label' => 'Phone', 'value' => $doctor->phone, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number.'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'consultation_fee', 'label' => 'Consultation fee (₹)', 'type' => 'number', 'value' => $doctor->consultation_fee ?? 0, 'required' => true, 'msg' => 'Enter a fee of 0 or more.'])</div>
        <div class="col-md-6">
            @include('partials.select', ['name' => 'user_id', 'label' => 'Linked login (optional)', 'options' => $doctorUsers->mapWithKeys(fn ($u) => [$u->id => $u->name.' — '.$u->email]), 'value' => $doctor->user_id, 'placeholder' => '— Not linked —'])
            <div class="form-text">Link a user with the Doctor role so they only see their own patients.</div>
        </div>
        <div class="col-12">@include('partials.active-switch', ['model' => $doctor])</div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('doctors.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add doctor' }}</button>
    </div>
</form>
@endsection
@push('scripts')<script>$('#consultation_fee').attr({ min: 0, step: '0.01' });</script>@endpush
