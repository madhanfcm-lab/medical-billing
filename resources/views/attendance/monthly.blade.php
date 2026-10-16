@extends('layouts.app')
@section('title', 'Monthly attendance')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('attendance.index') }}">Attendance</a></li>
    <li class="breadcrumb-item active">Monthly</li>
@endsection

@section('content')
<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-2">
    <a class="btn btn-light" href="{{ route('attendance.monthly', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}"><i class="bi bi-chevron-left"></i></a>
    <form method="GET" class="d-flex"><input type="month" name="month" value="{{ $month->format('Y-m') }}" max="{{ today()->format('Y-m') }}" class="form-control" onchange="this.form.submit()"></form>
    <a class="btn btn-light @if ($month->isSameMonth(today())) disabled @endif" href="{{ route('attendance.monthly', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}"><i class="bi bi-chevron-right"></i></a>
    <span class="fw-semibold ms-2">{{ $month->format('F Y') }}</span>
    <div class="ms-auto d-flex gap-2">
        @foreach ($statuses as $s)<span class="badge bg-{{ $s->tone() }}">{{ $s->code() }} = {{ $s->label() }}</span>@endforeach
    </div>
</div></div>

<div class="card"><div class="table-responsive">
    @if (count($rows) === 0)
        <div class="card-body">@include('partials.empty', ['icon' => 'bi-grid-3x3-gap', 'text' => 'No employees or attendance for this month.'])</div>
    @else
    <table class="table table-bordered att-grid mb-0">
        <thead class="table-light">
            <tr>
                <th class="name">Employee</th>
                @for ($d = 1; $d <= $days; $d++)<th>{{ $d }}</th>@endfor
                @foreach ($statuses as $s)<th class="text-{{ $s->tone() }}">{{ $s->code() }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td class="name"><a href="{{ route('employees.show', ['employee' => $row['employee'], 'month' => $month->format('Y-m')]) }}" class="fw-semibold text-decoration-none">{{ $row['employee']->name }}</a>
                    <div class="text-muted">{{ $row['employee']->employee_code }}</div></td>
                @for ($d = 1; $d <= $days; $d++)
                    @php $st = $row['cells'][$d] ?? null; $future = $month->copy()->day($d)->gt(today()); @endphp
                    <td @class(['att-future' => $future])>@if ($st)<span class="att-cell bg-{{ $st->tone() }}" title="{{ $st->label() }}">{{ $st->code() }}</span>@endif</td>
                @endfor
                @foreach ($statuses as $s)<td class="fw-bold">{{ $row['counts'][$s->value] }}</td>@endforeach
            </tr>
        @endforeach
        </tbody>
        <tfoot class="table-light"><tr>
            <th class="name">Total</th><th colspan="{{ $days }}"></th>
            @foreach ($statuses as $s)<th>{{ $totals[$s->value] }}</th>@endforeach
        </tr></tfoot>
    </table>
    @endif
</div></div>
@endsection
