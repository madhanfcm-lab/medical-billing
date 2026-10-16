@extends('layouts.app')
@php $editing = $user->exists; @endphp
@section('title', $editing ? 'Edit user' : 'New user')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
    <li class="breadcrumb-item active">{{ $editing ? 'Edit' : 'New' }}</li>
@endsection

@section('content')
@php $self = $editing && $user->is(auth()->user()); @endphp
<form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="card" style="max-width:720px" novalidate data-loading-form>
    @csrf @if ($editing) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-6">@include('partials.field', ['name' => 'name', 'label' => 'Full name', 'value' => $user->name, 'required' => true])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'email', 'label' => 'E-mail (login)', 'type' => 'email', 'value' => $user->email, 'required' => true, 'autocomplete' => 'off'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'phone', 'label' => 'Mobile', 'value' => $user->phone, 'maxlength' => 10, 'pattern' => '[6-9][0-9]{9}', 'msg' => 'Enter a valid 10-digit mobile number.'])</div>
        <div class="col-md-6">
            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
            <select name="role" id="role" class="form-select @error('role') is-invalid @enderror" required @disabled($self)>
                <option value="">Select role…</option>
                @foreach (\App\Enums\Role::options() as $value => $label)
                    <option value="{{ $value }}" @selected(old('role', $user->role_name) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($self) <input type="hidden" name="role" value="admin"> @endif
            <div class="invalid-feedback">{{ $errors->first('role') ?: 'Please choose a role.' }}</div>
        </div>
        <div class="col-md-6">@include('partials.field', ['name' => 'password', 'label' => $editing ? 'New password' : 'Password', 'type' => 'password', 'required' => ! $editing, 'autocomplete' => 'new-password', 'help' => $editing ? 'Leave blank to keep the current password.' : 'Minimum 8 characters.'])</div>
        <div class="col-md-6">@include('partials.field', ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => ! $editing, 'autocomplete' => 'new-password'])</div>
        <div class="col-12" id="branchBox">
            <label class="form-label">Works in <span class="text-danger">*</span></label>
            <div class="d-flex flex-wrap gap-3 @error('branches') is-invalid @enderror">
                @foreach ($branches as $b)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="branches[]" id="branch_{{ $b->id }}" value="{{ $b->id }}" @checked(in_array($b->id, array_map('intval', (array) old('branches', $assigned))))>
                        <label class="form-check-label" for="branch_{{ $b->id }}">{{ $b->name }}</label>
                    </div>
                @endforeach
            </div>
            <div class="invalid-feedback">{{ $errors->first('branches') }}</div>
            <div class="form-text">They can switch between the branches ticked here. Admins can always use every branch.</div>
        </div>
        @php $emp = $editing ? $user->employee : null; @endphp
        <div class="col-12"><hr class="my-1"></div>
        <div class="col-12">
            @if ($emp)
                <div class="alert alert-light border mb-0 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-person-vcard me-1"></i>On the staff list as <strong>{{ $emp->employee_code }}</strong> ({{ $emp->designation ?? '—' }}). Attendance and salary are recorded there.</span>
                    <a href="{{ route('employees.show', $emp) }}" class="btn btn-sm btn-outline-primary">Open</a>
                </div>
            @else
                <div class="form-check form-switch">
                    <input type="hidden" name="add_as_employee" value="0">
                    <input class="form-check-input" type="checkbox" role="switch" name="add_as_employee" id="add_as_employee" value="1" @checked(old('add_as_employee', ! $editing))>
                    <label class="form-check-label fw-semibold" for="add_as_employee">Also add to Employees (attendance &amp; salary)</label>
                    <div class="form-text mb-0">Anyone who works here and logs in normally belongs on the staff list too. Turn it off for someone who is not on your payroll.</div>
                </div>
                <div class="row g-3 mt-1" id="staffFields">
                    <div class="col-md-6">@include('partials.field', ['name' => 'designation', 'label' => 'Role / designation', 'value' => old('designation'), 'maxlength' => 100, 'help' => 'Leave blank to use the login role.'])</div>
                    <div class="col-md-6">@include('partials.field', ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'value' => old('joining_date', now()->toDateString())])</div>
                </div>
            @endif
        </div>
        <div class="col-12">
            <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1"
                       @checked(old('is_active', $user->is_active)) @disabled($self)>
                <label class="form-check-label" for="is_active">Active (can sign in)</label>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('users.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create user' }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
$('#add_as_employee').on('change', function () { $('#staffFields').toggle(this.checked); }).trigger('change');
</script>
@endpush
