@extends('layouts.app')
@section('title', $employee->name)
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('employees.index') }}">Employees</a></li>
    <li class="breadcrumb-item active">{{ $employee->employee_code }}</li>
@endsection
@section('actions')
    <a href="{{ route('salary-payments.create', ['employee' => $employee->id]) }}" class="btn btn-primary"><i class="bi bi-cash-coin me-1"></i>Add salary payment</a>
    <a href="{{ route('employees.edit', $employee) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-5">Code</dt><dd class="col-7"><strong>{{ $employee->employee_code }}</strong></dd>
                <dt class="col-5">Designation</dt><dd class="col-7">{{ $employee->designation ?? '—' }}</dd>
                <dt class="col-5">Phone</dt><dd class="col-7">{{ $employee->phone ?? '—' }}</dd>
                <dt class="col-5">Joined</dt><dd class="col-7">{{ format_date($employee->joining_date) }}</dd>
                <dt class="col-5">Login</dt><dd class="col-7">@if ($employee->user){{ $employee->user->email }}<div class="small text-muted">{{ \App\Enums\Role::tryFrom((string) $employee->user->role_name)?->label() }}</div>@else<span class="text-muted">No login</span>@endif</dd>
                <dt class="col-5">Status</dt><dd class="col-7">@include('partials.active-badge', ['active' => $employee->is_active])</dd>
            </dl>
        </div></div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Attendance</span>
                <form method="GET"><input type="month" name="month" value="{{ $month->format('Y-m') }}" max="{{ today()->format('Y-m') }}" class="form-control form-control-sm" onchange="this.form.submit()"></form>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center mb-3">
                    @foreach (\App\Enums\AttendanceStatus::cases() as $s)
                        <div class="col-3"><div class="fs-5 fw-bold text-{{ $s->tone() }}">{{ $counts[$s->value] }}</div><div class="small text-muted">{{ $s->code() }}</div></div>
                    @endforeach
                </div>
                <div class="d-flex flex-wrap gap-1">
                    @for ($d = 1; $d <= $days; $d++)
                        @php $st = $cells[$d] ?? null; @endphp
                        <span class="day-chip {{ $st ? 'bg-'.$st->tone().' text-white' : 'bg-light text-muted' }}" title="{{ $month->copy()->day($d)->format('d-m-Y') }}{{ $st ? ' · '.$st->label() : '' }}">{{ $d }}</span>
                    @endfor
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Salary payments</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>For month</th><th>Paid on</th><th>Mode</th><th>Reference</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($payments as $p)
                        <tr>
                            <td class="fw-semibold">{{ $p->month_label }}</td><td>{{ format_date($p->payment_date) }}</td><td>{{ $p->mode->label() }}</td>
                            <td>{{ $p->reference_no ?? '—' }}@if ($p->notes)<div class="small text-muted">{{ $p->notes }}</div>@endif</td>
                            <td class="text-end">{{ money($p->amount) }}</td>
                            <td class="text-end"><a href="{{ route('salary-payments.edit', $p) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">@include('partials.empty', ['icon' => 'bi-cash-coin', 'text' => 'No salary payments recorded yet.'])</td></tr>
                    @endforelse
                    </tbody>
                    @if ($payments->isNotEmpty())
                        <tfoot class="table-light"><tr><th colspan="4">Total paid</th><th class="text-end">{{ money($payments->sum(fn ($p) => (float) $p->amount)) }}</th><th></th></tr></tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
