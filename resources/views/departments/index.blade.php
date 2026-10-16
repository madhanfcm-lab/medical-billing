@extends('layouts.app')
@section('title', 'Departments')
@section('breadcrumbs')<li class="breadcrumb-item active">Departments</li>@endsection
@section('actions')<a href="{{ route('departments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New department</a>@endsection

@section('content')
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Name</th><th>Description</th><th>Doctors</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('departments.index')),
    columns: [{ data: 'name' }, { data: 'description', defaultContent: '—' }, { data: 'doctors_count', searchable: false }, { data: 'is_active', searchable: false }, { data: 'actions', orderable: false, searchable: false, className: 'text-end' }],
    language: { emptyTable: 'No departments yet. Add your first department.' }
});
</script>
@endpush
