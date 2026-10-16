@extends('layouts.app')
@section('title', 'New bill')
@section('subtitle', 'Choose the patient, add what was done, and take payment in the same step.')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('invoices.index') }}">Bills</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
@include('invoices._form', ['embedded' => false])
@endsection

@push('scripts')
@include('invoices._form-scripts', ['services' => $services, 'modes' => $modes, 'doctors' => $doctors])
@endpush
