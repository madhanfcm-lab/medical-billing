@extends('layouts.app')
@php $editing = $department->exists; @endphp
@section('title', $editing ? 'Edit department' : 'New department')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('departments.index') }}">Departments</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('departments.update', $department) : route('departments.store') }}" class="card" style="max-width:640px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body vstack gap-3">
        <div>@include('partials.field', ['name' => 'name', 'label' => 'Department name', 'value' => $department->name, 'required' => true, 'maxlength' => 100])</div>
        <div>@include('partials.field', ['name' => 'description', 'label' => 'Description', 'value' => $department->description, 'maxlength' => 255])</div>
        @include('partials.active-switch', ['model' => $department])
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('departments.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add department' }}</button>
    </div>
</form>
@endsection
