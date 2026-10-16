@extends('layouts.app')
@section('title', 'Patients')
@section('breadcrumbs')<li class="breadcrumb-item active">Patients</li>@endsection
@section('actions')
    @can('patients.manage')<a href="{{ route('patients.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Register patient</a>@endcan
@endsection

@section('content')
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>UHID</th><th>Name</th><th>Age</th><th>Gender</th><th>Phone</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('patients.index')), order: [[0, 'desc']],
    columns: [
        { data: 'uhid' }, { data: 'name' }, { data: 'age', searchable: false },
        { data: 'gender', searchable: false }, { data: 'phone' },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No patients registered yet.', searchPlaceholder: 'Name, phone or UHID' }
});
</script>
@endpush
