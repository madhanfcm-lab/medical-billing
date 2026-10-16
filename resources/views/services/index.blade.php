@extends('layouts.app')
@section('title', 'Services')
@section('breadcrumbs')<li class="breadcrumb-item active">Services</li>@endsection
@section('actions')<a href="{{ route('services.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New service</a>@endsection

@section('content')
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Name</th><th>Category</th><th>Default price</th><th>GST</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('services.index')),
    columns: [{ data: 'name' }, { data: 'category' }, { data: 'default_price' }, { data: 'gst_percent', searchable: false }, { data: 'is_active', searchable: false }, { data: 'actions', orderable: false, searchable: false, className: 'text-end' }],
    language: { emptyTable: 'No services yet. Add consultation and treatment items.' }
});
</script>
@endpush
