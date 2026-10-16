@extends('layouts.app')
@php $editing = $service->exists; @endphp
@section('title', $editing ? 'Edit service' : 'New service')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('services.index') }}">Services</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('services.update', $service) : route('services.store') }}" class="card" style="max-width:760px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-8">@include('partials.field', ['name' => 'name', 'label' => 'Service / treatment name', 'value' => $service->name, 'required' => true, 'maxlength' => 150])</div>
        <div class="col-md-4">@include('partials.select', ['name' => 'category', 'label' => 'Category', 'required' => true, 'options' => collect(\App\Enums\ServiceCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()]), 'value' => $service->category?->value])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'default_price', 'label' => 'Default price (₹)', 'type' => 'number', 'value' => $service->default_price, 'required' => true, 'msg' => 'Enter a price of 0 or more.', 'help' => 'Editable on each invoice.'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'gst_percent', 'label' => 'GST %', 'type' => 'number', 'value' => $service->gst_percent ?? 0, 'required' => true, 'msg' => 'Enter a GST between 0 and 100.'])</div>
        <div class="col-12">@include('partials.active-switch', ['model' => $service])</div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('services.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add service' }}</button>
    </div>
</form>
@endsection
@push('scripts')<script>$('#default_price').attr({ min: 0, step: '0.01' }); $('#gst_percent').attr({ min: 0, max: 100, step: '0.01' });</script>@endpush
