@extends('layouts.app')
@section('title', 'Visit · '.format_date($visit->visit_date))
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('visits.index') }}">Visits</a></li>
    <li class="breadcrumb-item active">{{ format_date($visit->visit_date) }}</li>
@endsection
@section('actions')
    @can('followups.manage')<a href="{{ route('follow-ups.create', ['visit' => $visit->id]) }}" class="btn btn-outline-primary"><i class="bi bi-telephone-plus me-1"></i>Set follow-up</a>@endcan
    @can('emi.manage')<a href="{{ route('emi-plans.create', ['visit' => $visit->id]) }}" class="btn btn-outline-primary"><i class="bi bi-calendar2-plus me-1"></i>Create EMI plan</a>@endcan
    @can('invoices.create')<a href="{{ route('invoices.create', ['visit' => $visit->id]) }}" class="btn btn-primary"><i class="bi bi-receipt me-1"></i>Create bill</a>@endcan
    @can('update', $visit)<a href="{{ route('visits.edit', $visit) }}" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>@endcan
@endsection

@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-header">Patient &amp; doctor</div><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-5">Patient</dt><dd class="col-7"><a href="{{ route('patients.show', $visit->patient_id) }}">{{ $visit->patient->name }}</a></dd>
                <dt class="col-5">UHID</dt><dd class="col-7">{{ $visit->patient->uhid }}</dd>
                <dt class="col-5">Age / Gender</dt><dd class="col-7">{{ $visit->patient->age_label }} · {{ $visit->patient->gender->label() }}</dd>
                <dt class="col-5">Doctor</dt><dd class="col-7">{{ $visit->doctor->name }}</dd>
                <dt class="col-5">Visit date</dt><dd class="col-7">{{ format_date($visit->visit_date) }}</dd>
            </dl>
            @if ($visit->patient->allergies)
                <div class="alert alert-warning py-2 mt-3 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i>{{ $visit->patient->allergies }}</div>
            @endif
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-header">Clinical notes</div><div class="card-body">
            @foreach (['chief_complaint' => 'Chief complaint', 'diagnosis' => 'Diagnosis', 'treatment_given' => 'Treatment given', 'notes' => 'Notes'] as $f => $label)
                <div class="mb-3"><div class="text-muted small">{{ $label }}</div><div>{!! $visit->$f ? nl2br(e($visit->$f)) : '—' !!}</div></div>
            @endforeach
        </div></div>
        <div class="card"><div class="card-header">Bills for this visit</div><div class="card-body">
            @forelse ($visit->invoices as $inv)
                <div class="d-flex justify-content-between align-items-center py-1">
                    <a href="{{ route('invoices.show', $inv) }}">{{ $inv->invoice_number }}</a>
                    <span>{{ money($inv->grand_total) }} @include('partials.invoice-status', ['status' => $inv->status])</span>
                </div>
            @empty
                @include('partials.empty', ['icon' => 'bi-receipt', 'text' => 'No bill has been created for this visit yet.'])
            @endforelse
        </div></div>
    </div>
</div>
@endsection
