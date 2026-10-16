@extends('layouts.app')
@section('title', 'EMI Dues')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('emi-plans.index') }}">EMI Plans</a></li>
    <li class="breadcrumb-item active">Dues</li>
@endsection

@section('content')
<div class="card mb-4">
    <div class="card-header text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Overdue instalments</div>
    <div class="card-body">
        <table id="dtOverdue" class="table table-hover align-middle w-100">
            <thead><tr><th>Patient</th><th>Phone</th><th>Plan</th><th>Inst.</th><th>Due date</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Remind / collect</th></tr></thead>
        </table>
    </div>
</div>
<div class="card">
    <div class="card-header"><i class="bi bi-calendar-event me-1"></i>Upcoming — next 7 days</div>
    <div class="card-body">
        <table id="dtUpcoming" class="table table-hover align-middle w-100">
            <thead><tr><th>Patient</th><th>Phone</th><th>Plan</th><th>Inst.</th><th>Due date</th><th class="text-end">Balance</th><th>Due</th><th class="text-end">Remind / collect</th></tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
const cols = [
    { data: 'patient' }, { data: 'phone', searchable: false, orderable: false }, { data: 'plan' },
    { data: 'installment_no', searchable: false }, { data: 'due_date', searchable: false },
    { data: 'balance', searchable: false, className: 'text-end' }, { data: 'days', searchable: false },
    { data: 'actions', orderable: false, searchable: false, className: 'text-end text-nowrap' },
];
const url = @json(route('emi-plans.dues'));
$('#dtOverdue').DataTable({ processing: true, serverSide: true, order: [[4, 'asc']], ajax: { url, data: d => { d.type = 'overdue'; } }, columns: cols,
    language: { emptyTable: 'No overdue instalments. 🎉' } });
$('#dtUpcoming').DataTable({ processing: true, serverSide: true, order: [[4, 'asc']], ajax: { url, data: d => { d.type = 'upcoming'; } }, columns: cols,
    language: { emptyTable: 'Nothing falls due in the next 7 days.' } });
</script>
@endpush
