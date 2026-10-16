@extends('layouts.app')
@section('title', 'Branches')
@section('breadcrumbs')<li class="breadcrumb-item active">Branches</li>@endsection
@section('actions')<a href="{{ route('branches.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New branch</a>@endsection

@section('content')
<div class="alert alert-light border small"><i class="bi bi-diagram-3 me-1"></i>
    Each branch keeps its own visits, bills, payments, EMI plans, follow-ups, appointments and staff attendance. Patients, services, doctors and settings are shared by the whole hospital, so a patient registered at one branch can be seen at another.
    Staff switch branch from the top bar.</div>
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Branch</th><th>Address</th><th>Phone</th><th>Staff</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('branches.index')),
    columns: [{ data: 'name' }, { data: 'address', orderable: false }, { data: 'phone' }, { data: 'users_count', searchable: false }, { data: 'is_active', searchable: false }, { data: 'actions', orderable: false, searchable: false, className: 'text-end' }],
    language: { emptyTable: 'No branches yet.' }
});
</script>
@endpush
