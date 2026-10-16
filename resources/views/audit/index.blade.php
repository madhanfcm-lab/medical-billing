@extends('layouts.app')
@section('title', 'Audit log')
@section('breadcrumbs')<li class="breadcrumb-item active">Audit log</li>@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="form-label small mb-1">Record type</label>
        <select id="fType" class="form-select form-select-sm"><option value="">All</option>@foreach ($types as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label small mb-1">Event</label>
        <select id="fEvent" class="form-select form-select-sm"><option value="">All</option>@foreach ($events as $e)<option value="{{ $e }}">{{ ucfirst($e) }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label small mb-1">User</label>
        <select id="fUser" class="form-select form-select-sm"><option value="">Everyone</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
</div></div>
<div class="card"><div class="card-body">
    <p class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Every change to invoices, payments, EMI plans, attendance and salary entries is recorded here with who made it and the before / after values. Entries cannot be edited or deleted.</p>
    <table id="dt" class="table table-hover align-top w-100">
        <thead><tr><th>When</th><th>User</th><th>Event</th><th>Record</th><th>Changes</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[0, 'desc']],
    ajax: { url: @json(route('audit.index')), data: d => { d.from = $('#fFrom').val(); d.to = $('#fTo').val(); d.type = $('#fType').val(); d.event = $('#fEvent').val(); d.user_id = $('#fUser').val(); } },
    columns: [
        { data: 'created_at', searchable: false }, { data: 'user' }, { data: 'event', searchable: false }, { data: 'record' },
        { data: 'changes', orderable: false, searchable: false },
    ],
    language: { emptyTable: 'No activity recorded yet.', searchPlaceholder: 'User or record number' }
});
$('#fFrom, #fTo, #fType, #fEvent, #fUser').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fFrom, #fTo, #fType, #fEvent, #fUser').val(''); table.ajax.reload(); });
</script>
@endpush
