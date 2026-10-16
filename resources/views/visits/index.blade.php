@extends('layouts.app')
@section('title', 'Visits')
@section('breadcrumbs')<li class="breadcrumb-item active">Visits</li>@endsection
@section('actions')
    @can('visits.manage')<a href="{{ route('visits.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New visit</a>@endcan
@endsection

@section('content')
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    @unless (auth()->user()->hasRole('doctor'))
    <div class="col-md-3"><label class="form-label small mb-1">Doctor</label>
        <select id="fDoctor" class="form-select form-select-sm"><option value="">All doctors</option>
            @foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    @endunless
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
</div></div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Chief complaint</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[0, 'desc']],
    ajax: { url: @json(route('visits.index')), data: d => { d.from = $('#fFrom').val(); d.to = $('#fTo').val(); d.doctor_id = $('#fDoctor').val(); } },
    columns: [
        { data: 'visit_date' }, { data: 'patient' }, { data: 'doctor', orderable: false, searchable: false },
        { data: 'chief_complaint', defaultContent: '—' },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No visits found.' }
});
$('#fFrom, #fTo, #fDoctor').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fFrom, #fTo, #fDoctor').val(''); table.ajax.reload(); });
</script>
@endpush
