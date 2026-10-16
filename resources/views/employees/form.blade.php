@extends('layouts.app')
@php $editing = $employee->exists; @endphp
@section('title', $editing ? 'Edit employee' : 'New employee')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('employees.update', $employee) : route('employees.store') }}" class="card" style="max-width:760px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-4">@include('partials.field', ['name' => 'employee_code', 'label' => 'Employee code', 'value' => $employee->employee_code, 'required' => true, 'maxlength' => 30, 'pattern' => '[A-Za-z0-9][A-Za-z0-9\-_\/]*', 'msg' => 'Letters, numbers, - _ / only.'])</div>
        <div class="col-md-8">@include('partials.field', ['name' => 'name', 'label' => 'Full name', 'value' => $employee->name, 'required' => true, 'maxlength' => 150])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'designation', 'label' => 'Role / designation', 'value' => $employee->designation, 'maxlength' => 100, 'placeholder' => 'e.g. Receptionist, Dental assistant'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'phone', 'label' => 'Phone', 'value' => $employee->phone, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number.'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'value' => $employee->joining_date?->format('Y-m-d'), 'required' => true, 'msg' => 'Enter a joining date (not in the future).'])</div>
        <div class="col-md-6 d-flex align-items-end">@include('partials.active-switch', ['model' => $employee])</div>
        <div class="col-12">
            <label for="user_id" class="form-label">Login account <span class="text-muted fw-normal">(optional)</span></label>
            <select name="user_id" id="user_id" class="form-select @error('user_id') is-invalid @enderror">
                <option value="">No login: this person does not use the software</option>
                @foreach ($loginUsers as $u)<option value="{{ $u->id }}" @selected((string) old('user_id', $employee->user_id) === (string) $u->id)>{{ $u->name }} · {{ $u->email }}</option>@endforeach
            </select>
            <div class="invalid-feedback">{{ $errors->first('user_id') }}</div>
            <div class="form-text">Link the person to their login (create it under Users first). Housekeeping and other staff who never sign in need no login.</div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ $editing ? route('employees.show', $employee) : route('employees.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary px-4">{{ $editing ? 'Save changes' : 'Add employee' }}</button>
    </div>
</form>
@endsection
@push('scripts')<script>$('#joining_date').attr('max', new Date().toISOString().slice(0, 10));</script>@endpush
