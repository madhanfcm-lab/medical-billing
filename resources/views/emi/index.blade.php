@extends('layouts.app')
@section('title', 'EMI Plans')
@section('breadcrumbs')<li class="breadcrumb-item active">EMI Plans</li>@endsection
@section('actions')
    <a href="{{ route('emi-plans.dues') }}" class="btn btn-outline-secondary"><i class="bi bi-alarm me-1"></i>Dues &amp; reminders</a>
    @can('emi.manage')<a href="{{ route('emi-plans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New EMI plan</a>@endcan
@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small mb-1">Status</label>
        <select id="fStatus" class="form-select form-select-sm"><option value="">All statuses</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
</div></div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Plan</th><th>Patient</th><th>Treatment</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Next due</th><th>Ends</th><th>Status</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[0, 'desc']],
    ajax: { url: @json(route('emi-plans.index')), data: d => { d.status = $('#fStatus').val(); } },
    columns: [
        { data: 'plan_number' }, { data: 'patient' }, { data: 'treatment_name' },
        { data: 'total_amount', searchable: false, className: 'text-end' },
        { data: 'paid', searchable: false, className: 'text-end' },
        { data: 'balance_amount', searchable: false, className: 'text-end' },
        { data: 'next_due_fmt', searchable: false }, { data: 'end_date', searchable: false },
        { data: 'status', searchable: false },
    ],
    language: { emptyTable: 'No EMI plans yet.', searchPlaceholder: 'Plan no., patient or treatment' }
});
$('#fStatus').on('change', () => table.ajax.reload());
</script>
@endpush
