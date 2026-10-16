@extends('layouts.app')
@section('title', 'Appointments')
@section('breadcrumbs')<li class="breadcrumb-item active">Appointments</li>@endsection
@section('actions')
    @can('appointments.manage')<a href="{{ route('appointments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Book appointment</a>@endcan
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
<ul class="nav nav-pills mb-3" id="tabs">
    @foreach (['today' => ['Today', 'today'], 'upcoming' => ['Upcoming', 'upcoming'], 'confirm' => ['Needs confirmation', 'confirm'], 'all' => ['All', null]] as $key => [$label, $count])
        <li class="nav-item"><button type="button" class="nav-link @if ($tab === $key) active @endif" data-tab="{{ $key }}">
            {{ $label }}@if ($count !== null) <span class="badge {{ $key === 'confirm' && $counts[$count] > 0 ? 'bg-warning text-dark' : 'bg-secondary' }} ms-1">{{ $counts[$count] }}</span>@endif
        </button></li>
    @endforeach
</ul>

<div class="card mb-3"><div class="card-body row g-2 align-items-end">
    @if ($doctors->isNotEmpty())
    <div class="col-md-3"><label class="form-label small mb-1">Doctor</label>
        <select id="fDoctor" class="form-select form-select-sm"><option value="">All doctors</option>@foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
    @endif
    <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" id="fFrom" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" id="fTo" class="form-control form-control-sm"></div>
    <div class="col-md-2 d-none" id="statusWrap"><label class="form-label small mb-1">Status</label>
        <select id="fStatus" class="form-select form-select-sm"><option value="">Any</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-sm btn-light" id="fReset">Reset</button></div>
</div></div>

<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>When</th><th>Patient</th><th>Doctor</th><th>Reason</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@include('appointments._modals')
@endsection

@push('scripts')
<script>
let tab = @json($tab);
const empty = { today: 'No appointments today.', upcoming: 'No upcoming appointments.', confirm: 'Everyone is confirmed. 🎉', all: 'No appointments found.' };
const table = $('#dt').DataTable({
    processing: true, serverSide: true, order: [[0, 'asc']],
    ajax: { url: @json(route('appointments.index')), data: d => { d.tab = tab; d.doctor_id = $('#fDoctor').val(); d.from = $('#fFrom').val(); d.to = $('#fTo').val(); d.status = $('#fStatus').val(); } },
    columns: [
        { data: 'when', searchable: false }, { data: 'patient' }, { data: 'doctor', orderable: false, searchable: false },
        { data: 'reason' }, { data: 'status', searchable: false, orderable: false },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end text-nowrap' },
    ],
    language: { emptyTable: empty[tab], searchPlaceholder: 'Patient, phone or reason' }
});
function setTab(t) {
    tab = t;
    $('#tabs .nav-link').removeClass('active').filter('[data-tab=' + t + ']').addClass('active');
    $('#statusWrap').toggleClass('d-none', t !== 'all');
    table.settings()[0].oLanguage.sEmptyTable = empty[t];
    table.order([0, t === 'all' ? 'desc' : 'asc']).ajax.reload();
    history.replaceState(null, '', '?tab=' + t);
}
$('#tabs').on('click', '[data-tab]', function () { setTab($(this).data('tab')); });
$('#fDoctor, #fFrom, #fTo, #fStatus').on('change', () => table.ajax.reload());
$('#fReset').on('click', () => { $('#fDoctor, #fFrom, #fTo, #fStatus').val(''); table.ajax.reload(); });
setTab(tab);
</script>
@endpush
