@extends('layouts.app')
@section('title', 'Salary payments')
@section('breadcrumbs')<li class="breadcrumb-item active">Salary payments</li>@endsection
@section('actions')<a href="{{ route('salary-payments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New salary payment</a>@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small mb-1">Employee</label>
        <select id="fEmployee" class="form-select form-select-sm"><option value="">All employees</option>
            @foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">For month</label><input type="month" id="fMonth" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid from</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Paid to</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
    <div class="col text-end"><span class="text-muted small">Total (filtered)</span> <strong class="fs-5" id="total">—</strong></div>
</div></div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Paid on</th><th>Employee</th><th>For month</th><th class="text-end">Amount</th><th>Mode</th><th>Reference</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[0, 'desc']],
    ajax: { url: @json(route('salary-payments.index')), data: d => { d.employee_id = $('#fEmployee').val(); d.month = $('#fMonth').val(); d.from = $('#fFrom').val(); d.to = $('#fTo').val(); } },
    columns: [
        { data: 'payment_date', searchable: false }, { data: 'employee' }, { data: 'for_month', searchable: false },
        { data: 'amount', searchable: false, className: 'text-end' }, { data: 'mode', searchable: false }, { data: 'reference_no' },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No salary payments recorded.', searchPlaceholder: 'Employee or reference' }
}).on('xhr.dt', (e, s, json) => { if (json && json.total) $('#total').text(json.total); });
$('#fEmployee, #fMonth, #fFrom, #fTo').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fEmployee, #fMonth, #fFrom, #fTo').val(''); table.ajax.reload(); });
</script>
@endpush
