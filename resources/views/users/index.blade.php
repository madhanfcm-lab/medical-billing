@extends('layouts.app')
@section('title', 'Users')
@section('breadcrumbs')<li class="breadcrumb-item active">Users</li>@endsection
@section('actions')<a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New user</a>@endsection

@section('content')
<div class="card"><div class="card-body">
    <table id="usersTable" class="table table-hover align-middle w-100">
        <thead><tr><th>Name</th><th>E-mail</th><th>Phone</th><th>Role</th><th>Branches</th><th>Staff record</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
<form id="toggleForm" method="POST" class="d-none">@csrf @method('PATCH')</form>
@endsection

@push('scripts')
<script>
$('#usersTable').DataTable({
    processing: true, serverSide: true, ajax: @json(route('users.index')),
    columns: [
        { data: 'name' }, { data: 'email' }, { data: 'phone', defaultContent: '—' },
        { data: 'role', orderable: false, searchable: false }, { data: 'branches', orderable: false, searchable: false }, { data: 'employee', orderable: false, searchable: false },
        { data: 'is_active', searchable: false },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No users found.' }
});

$(document).on('click', '.js-toggle', function () {
    const $b = $(this);
    confirmAction({ title: $b.data('title'), text: $b.data('text'), confirmText: 'Yes, continue' }).then(ok => {
        if (ok) $('#toggleForm').attr('action', $b.data('url')).trigger('submit');
    });
});
</script>
@endpush
