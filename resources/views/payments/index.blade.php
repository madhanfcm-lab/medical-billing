@extends('layouts.app')
@section('title', 'Receipts')
@section('subtitle', 'Every payment received, with the receipt to reprint.')
@section('breadcrumbs')<li class="breadcrumb-item active">Receipts</li>@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Mode</label>
        <select id="fMode" class="form-select form-select-sm"><option value="">All modes</option>
            @foreach ($modes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
</div></div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Receipt</th><th>Date</th><th>Patient</th><th>Against</th><th>Mode</th><th>Amount</th><th>Received by</th><th class="text-end"></th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[1, 'desc']],
    ajax: { url: @json(route('payments.index')), data: d => { d.from = $('#fFrom').val(); d.to = $('#fTo').val(); d.mode = $('#fMode').val(); } },
    columns: [
        { data: 'receipt_number' }, { data: 'payment_date' }, { data: 'patient' }, { data: 'against', searchable: false },
        { data: 'mode', searchable: false }, { data: 'amount', searchable: false }, { data: 'received_by', searchable: false },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No payments recorded.' }
});
$('#fFrom, #fTo, #fMode').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fFrom, #fTo, #fMode').val(''); table.ajax.reload(); });
</script>
@endpush
