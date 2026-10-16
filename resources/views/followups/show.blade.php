@extends('layouts.app')
@section('title', 'Follow-up · '.format_date($followUp->follow_up_date))
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('follow-ups.index') }}">Follow-ups</a></li>
    <li class="breadcrumb-item active">{{ format_date($followUp->follow_up_date) }}</li>
@endsection
@section('actions')
    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp me-1"></i>WhatsApp reminder</a>
    @can('manage', $followUp)
        <button class="btn btn-outline-success js-action" data-url="{{ route('follow-ups.complete', $followUp) }}" data-method="POST" data-title="Mark as completed?" data-text="{{ $followUp->patient->name }} — {{ $followUp->reason }}" data-confirm="Yes, completed"><i class="bi bi-check2-circle me-1"></i>Complete</button>
        @can('visits.manage')<a href="{{ route('visits.create', ['patient' => $followUp->patient_id, 'follow_up' => $followUp->id]) }}" class="btn btn-outline-primary"><i class="bi bi-clipboard2-plus me-1"></i>Complete &amp; record visit</a>@endcan
        <button class="btn btn-outline-secondary js-resched" data-url="{{ route('follow-ups.reschedule', $followUp) }}" data-date="{{ $followUp->follow_up_date->toDateString() }}"><i class="bi bi-calendar2-week me-1"></i>Reschedule</button>
        <button class="btn btn-outline-danger js-action" data-url="{{ route('follow-ups.cancel', $followUp) }}" data-method="POST" data-title="Cancel this follow-up?" data-text="This cannot be undone." data-confirm="Yes, cancel it"><i class="bi bi-x-circle me-1"></i>Cancel</button>
    @endcan
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($followUp->isOverdue())
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>This follow-up was due on {{ format_date($followUp->follow_up_date) }} and has not been actioned. Complete it, or reschedule.</div>
@endif
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Details</span> @include('followups._status', ['followUp' => $followUp])</div><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-sm-4">Patient</dt><dd class="col-sm-8"><a href="{{ route('patients.show', $followUp->patient_id) }}">{{ $followUp->patient->name }}</a> · {{ $followUp->patient->phone }}</dd>
                <dt class="col-sm-4">Date &amp; time</dt><dd class="col-sm-8">{{ format_date($followUp->follow_up_date) }}{{ $followUp->time_label ? ' · '.$followUp->time_label : '' }}</dd>
                <dt class="col-sm-4">Doctor</dt><dd class="col-sm-8">{{ $followUp->doctor?->name ?? '—' }}</dd>
                <dt class="col-sm-4">Reason</dt><dd class="col-sm-8">{{ $followUp->reason }}</dd>
                <dt class="col-sm-4">Notes</dt><dd class="col-sm-8">{!! $followUp->notes ? nl2br(e($followUp->notes)) : '—' !!}</dd>
                @if ($followUp->visit)<dt class="col-sm-4">From visit</dt><dd class="col-sm-8"><a href="{{ route('visits.show', $followUp->visit) }}">{{ format_date($followUp->visit->visit_date) }}</a></dd>@endif
                @if ($followUp->emiPlan)<dt class="col-sm-4">EMI plan</dt><dd class="col-sm-8"><a href="{{ route('emi-plans.show', $followUp->emiPlan) }}">{{ $followUp->emiPlan->plan_number }}</a></dd>@endif
                @if ($followUp->completed_at)<dt class="col-sm-4">Completed</dt><dd class="col-sm-8">{{ format_datetime($followUp->completed_at) }}
                    @if ($followUp->completedVisit) · <a href="{{ route('visits.show', $followUp->completedVisit) }}">visit recorded</a>@endif</dd>@endif
                @if ($followUp->cancel_reason)<dt class="col-sm-4">Cancel reason</dt><dd class="col-sm-8">{{ $followUp->cancel_reason }}</dd>@endif
                <dt class="col-sm-4">Created</dt><dd class="col-sm-8">{{ format_datetime($followUp->created_at) }} by {{ $followUp->creator?->name ?? '—' }}</dd>
            </dl>
        </div></div>
    </div>
    <div class="col-lg-5">
        @if ($followUp->rescheduledFrom || $followUp->rescheduledTo)
            <div class="card"><div class="card-header">Reschedule history</div><div class="card-body">
                @if ($followUp->rescheduledFrom)<div class="mb-2">Moved from <a href="{{ route('follow-ups.show', $followUp->rescheduledFrom) }}">{{ format_date($followUp->rescheduledFrom->follow_up_date) }}</a></div>@endif
                @if ($followUp->rescheduledTo)<div>Moved to <a href="{{ route('follow-ups.show', $followUp->rescheduledTo) }}">{{ format_date($followUp->rescheduledTo->follow_up_date) }}</a></div>@endif
            </div></div>
        @endif
    </div>
</div>
@include('followups._reschedule-modal')
@endsection
