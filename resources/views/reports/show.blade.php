@extends('layouts.app')
@section('title', $result->title)
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Reports</a></li>
    <li class="breadcrumb-item active">{{ $result->title }}</li>
@endsection
@section('actions')
    <a href="{{ route('reports.excel', ['report' => $def->key()] + $query) }}" class="btn btn-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a href="{{ route('reports.pdf', ['report' => $def->key()] + $query) }}" class="btn btn-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
@endsection

@section('content')
@php $uses = $def->filters(); @endphp
<form method="GET" class="card mb-3" id="filterForm"><div class="card-body row g-2 align-items-end">
    @if (($branchSwitcher['branches'] ?? collect())->count() > 1)
        <div class="col-md-3"><label class="form-label small mb-1">Branch</label>
            <select name="branch" class="form-select form-select-sm">
                <option value="all" @selected($branchChoice === 'all')>All branches</option>
                @foreach ($branchSwitcher['branches'] as $b)<option value="{{ $b->id }}" @selected($branchChoice === (string) $b->id)>{{ $b->name }}</option>@endforeach
            </select></div>
    @endif
    @if (in_array('period', $uses))
        <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label>
            <input type="date" name="from" value="{{ $filters->from?->toDateString() }}" class="form-control form-control-sm @error('from') is-invalid @enderror"><div class="invalid-feedback">{{ $errors->first('from') }}</div></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label>
            <input type="date" name="to" value="{{ $filters->to?->toDateString() }}" class="form-control form-control-sm @error('to') is-invalid @enderror"><div class="invalid-feedback">{{ $errors->first('to') }}</div></div>
        <div class="col-auto d-flex gap-1 align-items-end">
            @foreach ([['Today', today(), today()], ['This month', today()->startOfMonth(), today()], ['Last month', today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()]] as [$label, $a, $b])
                <button type="button" class="btn btn-sm btn-outline-secondary js-range" data-from="{{ $a->toDateString() }}" data-to="{{ $b->toDateString() }}">{{ $label }}</button>
            @endforeach
        </div>
    @endif
    @if (in_array('doctor', $uses))
        <div class="col-md-3"><label class="form-label small mb-1">Doctor</label>
            <select name="doctor_id" class="form-select form-select-sm"><option value="">All doctors</option>
                @foreach ($doctors as $d)<option value="{{ $d->id }}" @selected($filters->doctorId === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
    @endif
    @if (in_array('employee', $uses))
        <div class="col-md-3"><label class="form-label small mb-1">Employee</label>
            <select name="employee_id" class="form-select form-select-sm"><option value="">All employees</option>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected($filters->employeeId === $e->id)>{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></div>
    @endif
    @if (in_array('status', $uses))
        <div class="col-md-3"><label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">Any status</option>
                @foreach ($def->statusOptions() as $v => $l)<option value="{{ $v }}" @selected($filters->status === $v)>{{ $l }}</option>@endforeach</select></div>
    @endif
    @if (in_array('mode', $uses))
        <div class="col-md-2"><label class="form-label small mb-1">Payment mode</label>
            <select name="mode" class="form-select form-select-sm"><option value="">All modes</option>
                @foreach ($modes as $v => $l)<option value="{{ $v }}" @selected($filters->mode === $v)>{{ $l }}</option>@endforeach</select></div>
    @endif
    <div class="col-auto"><button class="btn btn-sm btn-primary px-3">Apply</button> <a href="{{ route('reports.show', $def->key()) }}" class="btn btn-sm btn-light">Reset</a></div>
</div></form>

<div class="mb-3 text-muted">{{ $result->subtitle }}</div>

@if ($result->summary)
<div class="row g-3 mb-4">
    @foreach ($result->summary as $f)
        <div class="col-6 col-md-4 col-xl-3"><div class="card stat-card h-100"><div class="card-body py-3">
            <div class="stat-label">{{ $f['label'] }}</div>
            <div class="stat-value">{{ \App\Reports\ReportFormatter::display($f['value'], $f['type']) }}</div>
        </div></div></div>
    @endforeach
</div>
@endif

@foreach ($result->sections as $section)
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between"><span>{{ $section->title }}</span>
            <span class="small text-muted fw-normal">{{ $section->totalRows ?? count($section->rows) }} {{ \Illuminate\Support\Str::plural('row', $section->totalRows ?? count($section->rows)) }}</span></div>
        @if (count($section->rows) === 0)
            <div class="card-body">@include('partials.empty', ['icon' => 'bi-inbox', 'text' => $section->empty])</div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    @foreach ($section->columns as $c)<th @class(['text-end' => \App\Reports\ReportFormatter::isNumeric($c['type'])])>{{ $c['label'] }}</th>@endforeach
                </tr></thead>
                <tbody>
                @foreach ($section->rows as $row)
                    <tr>@foreach ($section->columns as $c)
                        <td @class(['text-end' => \App\Reports\ReportFormatter::isNumeric($c['type']), 'text-nowrap' => in_array($c['type'], ['date', 'money'])])>{{ \App\Reports\ReportFormatter::display($row[$c['key']] ?? null, $c['type']) }}</td>
                    @endforeach</tr>
                @endforeach
                </tbody>
                @if ($section->totals !== null)
                <tfoot class="table-light fw-semibold"><tr>
                    @foreach ($section->columns as $c)
                        <td @class(['text-end' => \App\Reports\ReportFormatter::isNumeric($c['type'])])>{{ array_key_exists($c['key'], $section->totals) ? \App\Reports\ReportFormatter::display($section->totals[$c['key']], $c['type'] === 'date' ? 'text' : $c['type']) : '' }}</td>
                    @endforeach
                </tr></tfoot>
                @endif
            </table>
        </div>
        @endif
        @if ($section->isTruncated())
            <div class="card-footer small text-muted"><i class="bi bi-info-circle me-1"></i>Showing the first {{ number_format($limit) }} of {{ number_format($section->totalRows) }} rows. Totals cover all rows. Export to Excel or PDF for the full list.</div>
        @endif
    </div>
@endforeach
@endsection

@push('scripts')
<script>
$('.js-range').on('click', function () {
    $('[name=from]').val($(this).data('from')); $('[name=to]').val($(this).data('to')); $('#filterForm').trigger('submit');
});
</script>
@endpush
