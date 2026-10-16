@extends('layouts.app')
@section('title', 'Bills')
@section('subtitle', 'Every bill raised, with what has been paid and what is still due.')
@section('breadcrumbs')<li class="breadcrumb-item active">Bills</li>@endsection
@section('actions')
    @can('invoices.create')<a href="{{ route('invoices.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New bill</a>@endcan
@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Status</label>
        <select id="fStatus" class="form-select form-select-sm"><option value="">All statuses</option>
            @foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
    <div class="col-auto"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="fDue"><label class="form-check-label small" for="fDue">With balance due</label></div></div>
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
</div></div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Bill</th><th>Date</th><th>Patient</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end"></th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[1, 'desc']],
    ajax: { url: @json(route('invoices.index')), data: d => { d.from = $('#fFrom').val(); d.to = $('#fTo').val(); d.status = $('#fStatus').val(); d.due_only = $('#fDue').is(':checked') ? 1 : 0; } },
    columns: [
        { data: 'invoice_number' }, { data: 'invoice_date', searchable: false }, { data: 'patient' },
        { data: 'grand_total', searchable: false, className: 'text-end' }, { data: 'paid_amount', searchable: false, className: 'text-end' },
        { data: 'balance_amount', searchable: false, className: 'text-end' }, { data: 'status', searchable: false },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end text-nowrap' },
    ],
    language: { emptyTable: 'No invoices found.', searchPlaceholder: 'Invoice no. or patient' }
});
$('#fFrom, #fTo, #fStatus, #fDue').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fFrom, #fTo, #fStatus').val(''); $('#fDue').prop('checked', false); table.ajax.reload(); });
</script>
@endpush
