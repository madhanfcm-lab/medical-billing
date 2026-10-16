@extends('layouts.app')
@section('title', 'Employees')
@section('breadcrumbs')<li class="breadcrumb-item active">Employees</li>@endsection
@section('actions')<a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New employee</a>@endsection

@section('content')
@if ($unlinkedLogins > 0)
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-people me-1"></i><strong>{{ $unlinkedLogins }} {{ \Illuminate\Support\Str::plural('person', $unlinkedLogins) }} can log in but {{ $unlinkedLogins === 1 ? 'is' : 'are' }} not on this list.</strong>
            Staff who log in usually belong here too, so attendance and salary can be recorded for them.</span>
        <button class="btn btn-sm btn-primary js-action" data-url="{{ route('employees.sync-logins') }}" data-method="POST"
                data-title="Add {{ $unlinkedLogins }} {{ \Illuminate\Support\Str::plural('login', $unlinkedLogins) }} to Employees?"
                data-text="Each gets an employee code and their login role as designation. You can edit them afterwards." data-confirm="Yes, add them">Add them to Employees</button>
    </div>
@endif
<div class="card"><div class="card-body">
    <table id="dt" class="table table-hover align-middle w-100">
        <thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Phone</th><th>Login</th><th>Joined</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    </table>
</div></div>
@endsection

@push('scripts')
<script>
$('#dt').DataTable({
    processing: true, serverSide: true, ajax: @json(route('employees.index')),
    columns: [
        { data: 'employee_code' }, { data: 'name' }, { data: 'designation' }, { data: 'phone' }, { data: 'login', orderable: false, searchable: false },
        { data: 'joining_date', searchable: false }, { data: 'is_active', searchable: false },
        { data: 'actions', orderable: false, searchable: false, className: 'text-end' },
    ],
    language: { emptyTable: 'No employees yet. Add your first employee.' }
});
</script>
@endpush
