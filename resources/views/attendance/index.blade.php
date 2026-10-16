@extends('layouts.app')
@section('title', 'Attendance')
@section('breadcrumbs')<li class="breadcrumb-item active">Attendance</li>@endsection
@section('actions')
    <a href="{{ route('attendance.monthly', ['month' => $date->format('Y-m')]) }}" class="btn btn-outline-secondary"><i class="bi bi-grid-3x3-gap me-1"></i>Monthly report</a>
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-2">
    <a class="btn btn-light" href="{{ route('attendance.index', ['date' => $date->copy()->subDay()->toDateString()]) }}" title="Previous day"><i class="bi bi-chevron-left"></i></a>
    <input type="date" id="dateSel" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" class="form-control" style="max-width:180px">
    <a class="btn btn-light @if ($date->isToday()) disabled @endif" href="{{ route('attendance.index', ['date' => $date->copy()->addDay()->toDateString()]) }}" title="Next day"><i class="bi bi-chevron-right"></i></a>
    @unless ($date->isToday())<a class="btn btn-outline-primary" href="{{ route('attendance.index') }}">Today</a>@endunless
    <span class="fw-semibold ms-2">{{ $date->format('l, d-m-Y') }}</span>
    <div class="ms-auto d-flex flex-wrap gap-2" id="chips">
        @foreach ($statuses as $s)<span class="badge bg-{{ $s->tone() }}-subtle text-{{ $s->tone() }}-emphasis fs-6" data-count="{{ $s->value }}">{{ $s->code() }}: 0</span>@endforeach
        <span class="badge bg-secondary-subtle text-secondary-emphasis fs-6" data-count="unmarked">Not marked: 0</span>
    </div>
</div></div>

@unless ($editable)
    <div class="alert alert-warning"><i class="bi bi-lock me-1"></i>This date is read-only for you. Only an admin can change attendance older than {{ \App\Services\AttendanceService::STAFF_EDIT_WINDOW_DAYS }} days.</div>
@elseif ($oldest)
    <div class="alert alert-light border small mb-3"><i class="bi bi-info-circle me-1"></i>You can mark or correct attendance from {{ format_date($oldest) }} to today.</div>
@endunless

<form method="POST" action="{{ route('attendance.store') }}" id="sheet" novalidate data-loading-form>
    @csrf
    <input type="hidden" name="date" value="{{ $date->toDateString() }}">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>{{ $employees->count() }} {{ \Illuminate\Support\Str::plural('employee', $employees->count()) }}</span>
            @if ($editable && $employees->isNotEmpty())<button type="button" class="btn btn-sm btn-outline-success" id="markAll"><i class="bi bi-check2-all me-1"></i>Mark all present</button>@endif
        </div>
        @if ($employees->isEmpty())
            <div class="card-body">@include('partials.empty', ['icon' => 'bi-people', 'text' => 'No active employees for this date. Add employees under Employees.'])</div>
        @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th style="width:36px">#</th><th>Employee</th><th>Attendance</th><th style="min-width:200px">Remark</th></tr></thead>
                <tbody>
                @foreach ($employees as $e)
                    @php $rec = $records->get($e->id); $cur = old("attendance.{$e->id}.status", $rec?->status?->value); @endphp
                    <tr>
                        <td class="text-muted">{{ $loop->iteration }}</td>
                        <td><div class="fw-semibold">{{ $e->name }}</div>
                            <div class="small text-muted">{{ $e->employee_code }}{{ $e->designation ? ' · '.$e->designation : '' }}@if (! $e->is_active) · <span class="text-danger">inactive</span>@endif</div>
                            @if ($rec?->updater)<div class="small text-muted">saved by {{ $rec->updater->name }}</div>@endif</td>
                        <td>
                            <div class="btn-group status-pick" role="group">
                                @foreach ($statuses as $s)
                                    <input type="radio" class="btn-check js-status" name="attendance[{{ $e->id }}][status]" id="a{{ $e->id }}_{{ $s->value }}" value="{{ $s->value }}" @checked($cur === $s->value) @disabled(! $editable) autocomplete="off">
                                    <label class="btn btn-sm btn-outline-{{ $s->tone() }}" for="a{{ $e->id }}_{{ $s->value }}">{{ $s->label() }}</label>
                                @endforeach
                            </div>
                        </td>
                        <td><input type="text" name="attendance[{{ $e->id }}][remark]" maxlength="255" value="{{ old("attendance.{$e->id}.remark", $rec?->remark) }}" class="form-control form-control-sm" placeholder="Optional" @disabled(! $editable)></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($editable)
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="text-muted small">Employees left unmarked are skipped.</span>
            <button class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save attendance</button>
        </div>
        @endif
        @endif
    </div>
</form>
@endsection

@push('scripts')
<script>
const labels = @json(collect($statuses)->mapWithKeys(fn ($s) => [$s->value => $s->code()]));
function count() {
    const c = {}; Object.keys(labels).forEach(k => c[k] = $('.js-status[value=' + k + ']:checked').length);
    const total = $('tbody tr').length, marked = Object.values(c).reduce((a, b) => a + b, 0);
    Object.keys(labels).forEach(k => $('[data-count=' + k + ']').text(labels[k] + ': ' + c[k]));
    $('[data-count=unmarked]').text('Not marked: ' + (total - marked));
}
$(document).on('change', '.js-status', count);
$('#markAll').on('click', () => { $('.js-status[value=present]:not(:disabled)').prop('checked', true); count(); toastr.info('Everyone marked present — change anyone who is absent, then Save.'); });
$('#dateSel').on('change', function () { if (this.value) location.href = @json(route('attendance.index')) + '?date=' + this.value; });
count();
</script>
@endpush
