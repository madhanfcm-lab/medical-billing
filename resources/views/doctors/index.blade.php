@extends('layouts.app')
@section('title', 'Doctors')
@section('breadcrumbs')<li class="breadcrumb-item active">Doctors</li>@endsection
@section('actions')<a href="{{ route('doctors.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New doctor</a>@endsection

@section('content')
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Name</th><th>Specialization</th><th>Department</th><th>Phone</th><th>Fee</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('doctors.index')),
    columns: [{ data: 'name' }, { data: 'specialization', defaultContent: '—' }, { data: 'department', orderable: false, searchable: false }, { data: 'phone', defaultContent: '—' }, { data: 'consultation_fee', searchable: false }, { data: 'is_active', searchable: false }, { data: 'actions', orderable: false, searchable: false, className: 'text-end' }],
    language: { emptyTable: 'No doctors yet. Add your first doctor.' }
});
</script>
@endpush
