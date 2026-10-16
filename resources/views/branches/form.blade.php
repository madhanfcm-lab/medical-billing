@extends('layouts.app')
@php $editing = $branch->exists; @endphp
@section('title', $editing ? 'Edit branch' : 'New branch')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('branches.index') }}">Branches</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('branches.update', $branch) : route('branches.store') }}" class="card" style="max-width:720px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-8">@include('partials.field', ['name' => 'name', 'label' => 'Branch name', 'value' => $branch->name, 'required' => true, 'maxlength' => 120, 'placeholder' => 'e.g. Velammal Nagar'])</div>
        <div class="col-md-4">@include('partials.field', ['name' => 'code', 'label' => 'Short code', 'value' => $branch->code, 'required' => true, 'maxlength' => 12, 'placeholder' => 'VEL'])</div>
        <div class="col-12">
            <label for="address" class="form-label">Address</label>
            <textarea name="address" id="address" rows="2" maxlength="500" class="form-control @error('address') is-invalid @enderror">{{ old('address', $branch->address) }}</textarea>
            <div class="form-text">Printed on this branch's bills and receipts. Leave blank to use the hospital address from Settings.</div>
        </div>
        <div class="col-md-6">@include('partials.field', ['name' => 'phone', 'label' => 'Phone', 'value' => $branch->phone, 'maxlength' => 20])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $branch->email, 'maxlength' => 150])</div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input @error('is_active') is-invalid @enderror" type="checkbox" role="switch" name="is_active" id="is_active" value="1" @checked(old('is_active', $branch->is_active))>
                <label class="form-check-label" for="is_active">Active (staff can work in it)</label>
                <div class="invalid-feedback d-block">{{ $errors->first('is_active') }}</div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('branches.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Add branch' }}</button>
    </div>
</form>
@endsection
