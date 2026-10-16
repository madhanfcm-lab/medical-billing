@extends('layouts.app')
@php $editing = $payment->exists; @endphp
@section('title', $editing ? 'Edit salary payment' : 'New salary payment')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('salary-payments.index') }}">Salary payments</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
<form method="POST" action="{{ $editing ? route('salary-payments.update', $payment) : route('salary-payments.store') }}" class="card" style="max-width:760px" novalidate data-loading-form data-confirm-fn="confirmSalary">
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-12 alert alert-light border mb-0 small"><i class="bi bi-info-circle me-1"></i>This is a record of money already paid. Nothing is calculated: enter the amount you actually paid.</div>
        <div class="col-md-6">
            <label class="form-label" for="employee_id">Employee <span class="text-danger">*</span></label>
            <select name="employee_id" id="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                <option value="">Select employee…</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected((string) old('employee_id', $payment->employee_id) === (string) $e->id)>{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach
            </select>
            <div class="invalid-feedback">{{ $errors->first('employee_id') ?: 'Select an employee.' }}</div>
        </div>
        <div class="col-md-3">@include('partials.field', ['name' => 'for_month', 'label' => 'Salary for month', 'type' => 'month', 'value' => old('for_month') ? substr(old('for_month'), 0, 7) : $payment->for_month?->format('Y-m'), 'required' => true, 'msg' => 'Choose the month.'])</div>
        <div class="col-md-3">@include('partials.field', ['name' => 'payment_date', 'label' => 'Paid on', 'type' => 'date', 'value' => $payment->payment_date?->format('Y-m-d'), 'required' => true, 'msg' => 'Enter the payment date (not in the future).'])</div>
        <div class="col-md-4">@include('partials.field', ['name' => 'amount', 'label' => 'Amount (₹)', 'type' => 'number', 'value' => $payment->amount, 'required' => true, 'msg' => 'Enter the amount paid.'])</div>
        <div class="col-md-4">
            <label class="form-label" for="mode">Mode <span class="text-danger">*</span></label>
            <select name="mode" id="mode" class="form-select @error('mode') is-invalid @enderror" required>
                @foreach ($modes as $m)<option value="{{ $m['value'] }}" @selected(old('mode', $payment->mode?->value) === $m['value'])>{{ $m['label'] }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4 d-none" id="refWrap">
            <label class="form-label" for="reference_no">Reference no. <span class="text-danger">*</span></label>
            <input name="reference_no" id="reference_no" maxlength="100" value="{{ old('reference_no', $payment->reference_no) }}" class="form-control @error('reference_no') is-invalid @enderror">
            <div class="invalid-feedback">{{ $errors->first('reference_no') ?: 'Required for non-cash payments.' }}</div>
        </div>
        <div class="col-12">@include('partials.field', ['name' => 'notes', 'label' => 'Note', 'value' => $payment->notes, 'maxlength' => 255, 'placeholder' => 'e.g. Advance, bonus, partial payment'])</div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('salary-payments.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary px-4">{{ $editing ? 'Save changes' : 'Record payment' }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
const modes = @json($modes), today = new Date().toISOString().slice(0, 10);
$('#amount').attr({ min: 0.01, step: '0.01' });
$('#payment_date').attr('max', today);
$('#for_month').attr('max', today.slice(0, 7));
function sync() {
    const m = modes.find(x => x.value === $('#mode').val());
    $('#refWrap').toggleClass('d-none', !(m && m.ref));
    $('#reference_no').prop('required', !!(m && m.ref));
}
$('#mode').on('change', sync); sync();
window.confirmSalary = f => {
    const amt = parseFloat(f.amount.value) || 0;
    return { title: 'Record this salary payment?', icon: 'question', confirmText: 'Yes, record it',
        text: '₹' + amt.toLocaleString('en-IN', { minimumFractionDigits: 2 }) + ' to ' + f.employee_id.selectedOptions[0].text + ' for ' + f.for_month.value + '.' };
};
</script>
@endpush
