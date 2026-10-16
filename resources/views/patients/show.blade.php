@extends('layouts.app')
@section('title', $patient->name)
@section('hide-header', '1')

@php
    $due = (float) $financials['balance'];
    $collectable = (float) $dues['collectable'];
    $canBill = (bool) $billing;
    $canReceiveBills = auth()->user()->can('payments.collect') && $collectable > 0;
    $canReceiveEmi = auth()->user()->can('emi.manage') && $dues['open_plans']->isNotEmpty();
    $canReceive = $canReceiveBills || $canReceiveEmi;
    $lastVisit = $visits->first();
    $upcoming = $followUps->filter(fn ($f) => $f->status->value === 'scheduled')->sortBy('follow_up_date')->take(3);
    $hasToday = $today['visits']->isNotEmpty() || $today['bills']->isNotEmpty() || $today['followups']->isNotEmpty() || $today['appointments']->isNotEmpty() || (float) $today['paid_today'] > 0;
    $autoBill = request()->boolean('bill') || ($errors->any() && old('items') !== null && old('return_to'));
    $autoReceive = request()->boolean('receive') || request()->filled('receive_plan') || ($errors->hasAny(['amount', 'reference_no', 'payment_date', 'mode']) && old('amount') !== null);
@endphp

@section('content')
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="{{ route('patients.index') }}">Patients</a></li>
    <li class="breadcrumb-item active">{{ $patient->uhid }}</li>
</ol></nav>

{{-- What just happened: print links, next step --}}
@if (session('bill_id') || session('batch_ref') || session('receipt_id'))
    <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-check-circle-fill me-1"></i><strong>Done.</strong> {{ session('success') }}</span>
        <span class="d-flex flex-wrap gap-2">
            @if (session('bill_id'))
                <a class="btn btn-sm btn-success" target="_blank" href="{{ route('invoices.print', session('bill_id')) }}"><i class="bi bi-printer me-1"></i>Print bill</a>
            @endif
            @if (session('batch_ref') && session('receipt_count', 1) > 1)
                <a class="btn btn-sm btn-success" target="_blank" href="{{ route('payments.batch', session('batch_ref')) }}"><i class="bi bi-printer me-1"></i>Print receipt ({{ session('receipt_count') }} bills)</a>
                <a class="btn btn-sm btn-outline-success" href="{{ route('payments.batch.pdf', session('batch_ref')) }}">PDF</a>
            @elseif (session('receipt_id'))
                <a class="btn btn-sm btn-success" target="_blank" href="{{ route('payments.receipt', session('receipt_id')) }}"><i class="bi bi-printer me-1"></i>Print receipt</a>
                <a class="btn btn-sm btn-outline-success" href="{{ route('payments.receipt.pdf', session('receipt_id')) }}">PDF</a>
            @endif
            @if (session('bill_id'))
                <a class="btn btn-sm btn-outline-success" href="{{ route('invoices.show', session('bill_id')) }}">Open bill</a>
            @endif
        </span>
    </div>
@elseif (session('just_registered') && $canBill && ! $autoBill)
    <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-person-check-fill me-1"></i><strong>{{ $patient->name }} is registered</strong> as {{ $patient->uhid }}. Ready to bill?</span>
        <button class="btn btn-sm btn-success" data-bs-toggle="offcanvas" data-bs-target="#billDrawer"><i class="bi bi-receipt me-1"></i>Start the bill</button>
    </div>
@endif
@if ($errors->any() && ! $autoBill && ! $autoReceive)
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

{{-- ───────── Patient header ───────── --}}
<div class="card patient-hero mb-4">
    <div class="hero-body">
        <span class="avatar avatar-lg avatar-tone-{{ $patient->id % 6 + 1 }}">{{ strtoupper(collect(explode(' ', trim($patient->name)))->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) }}</span>

        <div class="who">
            <h1>{{ $patient->name }}</h1>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="chip chip-brand"><i class="bi bi-upc-scan"></i>{{ $patient->uhid }}</span>
                <span class="chip"><i class="bi bi-person"></i>{{ $patient->gender->label() }} · {{ $patient->age_label }}</span>
                @if ($patient->blood_group)<span class="chip"><i class="bi bi-droplet-half"></i>{{ $patient->blood_group->value }}</span>@endif
                <span class="chip"><i class="bi bi-calendar-plus"></i>Since {{ format_date($patient->created_at) }}</span>
            </div>
            <div class="mt-2 text-muted d-flex flex-wrap gap-x-3 gap-2 align-items-center">
                <a href="tel:{{ $patient->phone }}" class="text-body fw-semibold"><i class="bi bi-telephone me-1 text-muted"></i>{{ $patient->phone }}</a>
                <a href="https://wa.me/91{{ $patient->phone }}" target="_blank" rel="noopener" class="text-success small fw-semibold"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
                @if ($patient->address)<span class="small"><i class="bi bi-geo-alt me-1"></i>{{ \Illuminate\Support\Str::limit($patient->address, 60) }}</span>@endif
            </div>
            @if ($patient->allergies)
                <div class="alert-strip"><i class="bi bi-exclamation-triangle-fill"></i><span><strong>Medical note:</strong> {{ $patient->allergies }}</span></div>
            @endif
        </div>

        <div class="hero-actions ms-lg-auto">
            @if ($due > 0 || $canBill || auth()->user()->can('invoices.view'))
                <div class="due-box {{ $due > 0 ? 'has-due' : 'no-due' }}">
                    <div class="lbl">{{ $due > 0 ? 'Balance due' : 'Balance' }}</div>
                    <div class="amt">{{ $due > 0 ? money($due) : 'All paid' }}</div>
                </div>
            @endif
            @if ($canReceive)
                <button class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#receiveModal"><i class="bi bi-cash-coin me-1"></i>Receive payment</button>
            @endif
            @if ($canBill)
                <button class="btn btn-primary btn-lg" data-bs-toggle="offcanvas" data-bs-target="#billDrawer"><i class="bi bi-plus-lg me-1"></i>New bill</button>
            @endif
            @canany(['visits.manage', 'appointments.manage', 'followups.manage', 'emi.manage', 'patients.manage'])
            <div class="dropdown">
                <button class="btn btn-light btn-lg" data-bs-toggle="dropdown" aria-expanded="false">More <i class="bi bi-chevron-down ms-1 small"></i></button>
                <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px">
                    @can('visits.manage')<li><a class="dropdown-item" href="{{ route('visits.create', ['patient' => $patient->id]) }}"><i class="bi bi-clipboard2-plus"></i>Record visit</a></li>@endcan
                    @can('appointments.manage')<li><a class="dropdown-item" href="{{ route('appointments.create', ['patient' => $patient->id]) }}"><i class="bi bi-calendar-plus"></i>Book appointment</a></li>@endcan
                    @can('followups.manage')<li><a class="dropdown-item" href="{{ route('follow-ups.create', ['patient' => $patient->id]) }}"><i class="bi bi-telephone-plus"></i>Set follow-up</a></li>@endcan
                    @can('emi.manage')<li><a class="dropdown-item" href="{{ route('emi-plans.create', ['patient' => $patient->id]) }}"><i class="bi bi-calendar2-plus"></i>New EMI plan</a></li>@endcan
                    @can('patients.manage')<li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="{{ route('patients.edit', $patient) }}"><i class="bi bi-pencil"></i>Edit patient details</a></li>@endcan
                </ul>
            </div>
            @endcanany
        </div>
    </div>

    <div class="today-strip">
        <span class="tl">Today</span>
        @if ($hasToday)
            @foreach ($today['visits'] as $v)<span class="chip chip-brand"><i class="bi bi-clipboard2-pulse"></i>Visit · {{ $v->doctor->name }}</span>@endforeach
            @foreach ($today['bills'] as $b)<span class="chip"><i class="bi bi-receipt"></i>{{ $b->invoice_number }} · {{ money($b->grand_total) }} · {{ $b->status->label() }}</span>@endforeach
            @if ((float) $today['paid_today'] > 0)<span class="chip chip-ok"><i class="bi bi-cash-coin"></i>{{ money($today['paid_today']) }} received</span>@endif
            @foreach ($today['appointments'] as $a)<span class="chip chip-brand"><i class="bi bi-calendar-event"></i>Appointment {{ $a->time_label }} · {{ $a->status->label() }}</span>@endforeach
            @foreach ($today['followups'] as $f)<span class="chip chip-warn"><i class="bi bi-telephone-outbound"></i>Follow-up {{ $f->time_label ?? 'today' }}</span>@endforeach
        @else
            <span class="text-muted small">Nothing recorded yet today.@if ($canBill) Click <strong>New bill</strong> to start.@endif</span>
        @endif
    </div>
</div>

{{-- ───────── Numbers ───────── --}}
<div class="row g-3 mb-4">
    @foreach ([
        ['Total billed', money($financials['billed']), ''],
        ['Total paid', money($financials['paid']), 'text-success'],
        ['Balance due', money($financials['balance']), $due > 0 ? 'text-danger' : ''],
        ['Visits', $visits->count(), ''],
        ['Last visit', $lastVisit ? format_date($lastVisit->visit_date) : '—', ''],
    ] as [$label, $value, $cls])
        <div class="col-6 col-md-4 col-xl"><div class="card mini-stat h-100"><div class="stat-label">{{ $label }}</div><div class="v {{ $cls }}">{{ $value }}</div></div></div>
    @endforeach
</div>

<div class="row g-4">
    {{-- ───────── Main: everything that happened ───────── --}}
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header pb-0 border-0">
                <ul class="nav nav-tabs" role="tablist" id="profileTabs">
                    @foreach (['timeline' => 'Timeline', 'bills' => 'Bills & receipts', 'visits' => 'Visits', 'emi' => 'EMI Plans', 'followups' => 'Follow-ups'] as $key => $label)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if ($loop->first) active @endif" data-bs-toggle="tab" data-bs-target="#tab-{{ $key }}" type="button" role="tab">{{ $label }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body tab-content">

                {{-- Timeline --}}
                <div class="tab-pane fade show active" id="tab-timeline" role="tabpanel">
                    @if ($timeline->isEmpty())
                        @include('partials.empty', ['icon' => 'bi-clock-history', 'text' => 'Nothing has happened for this patient yet. Start with a bill or a visit.'])
                    @else
                        <ul class="timeline">
                            @php $prev = null; @endphp
                            @foreach ($timeline as $e)
                                @php $day = $e['date']->isToday() ? 'Today' : ($e['date']->isYesterday() ? 'Yesterday' : $e['date']->format('d M Y')); @endphp
                                @if ($day !== $prev)<li class="tl-day">{{ $day }}</li>@php $prev = $day; @endphp @endif
                                <li class="tl-item">
                                    <span class="tl-dot t-{{ $e['tone'] }}"><i class="bi {{ $e['icon'] }}"></i></span>
                                    <div class="tl-body">
                                        <div class="d-flex justify-content-between gap-3 align-items-start">
                                            <div class="min-w-0">
                                                @if ($e['url'])<a href="{{ $e['url'] }}" class="tl-title">{{ $e['title'] }}</a>@else<span class="tl-title">{{ $e['title'] }}</span>@endif
                                                @if ($e['badge'])<span class="badge {{ $e['badge']['class'] }} ms-1">{{ $e['badge']['label'] }}</span>@endif
                                                @if ($e['detail'])<div class="text-muted small">{{ $e['detail'] }}</div>@endif
                                            </div>
                                            @if ($e['amount'] !== null)<span class="tl-amt {{ $e['type'] === 'payment' ? 'text-success' : '' }}">{{ $e['type'] === 'payment' ? '+ ' : '' }}{{ money($e['amount']) }}</span>@endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Bills & receipts --}}
                <div class="tab-pane fade" id="tab-bills" role="tabpanel">
                    @if ($invoices->isEmpty())
                        @include('partials.empty', ['icon' => 'bi-receipt', 'text' => 'No bills yet.'])
                    @else
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>Bill</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
                            <tbody>@foreach ($invoices as $i)
                                <tr>
                                    <td><a href="{{ route('invoices.show', $i) }}" class="fw-semibold">{{ $i->invoice_number }}</a></td><td>{{ format_date($i->invoice_date) }}</td>
                                    <td class="text-end">{{ money($i->grand_total) }}</td><td class="text-end">{{ money($i->paid_amount) }}</td>
                                    <td class="text-end {{ (float) $i->balance_amount > 0 ? 'text-danger fw-semibold' : '' }}">{{ money($i->balance_amount) }}</td>
                                    <td>@include('partials.invoice-status', ['status' => $i->status])</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('invoices.print', $i) }}" target="_blank" class="btn btn-sm btn-icon btn-light" title="Print bill"><i class="bi bi-printer"></i></a>
                                    </td>
                                </tr>
                            @endforeach</tbody></table></div>
                    @endif

                    <h6 class="mt-4 mb-2 text-muted text-uppercase small fw-semibold" style="letter-spacing:.06em">Receipts</h6>
                    @if ($payments->isEmpty())
                        @include('partials.empty', ['icon' => 'bi-cash-coin', 'text' => 'No payments yet.'])
                    @else
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Against</th><th>Mode</th><th class="text-end">Amount</th><th></th></tr></thead>
                            <tbody>@foreach ($payments as $p)
                                <tr><td class="fw-semibold">{{ $p->receipt_number }}</td><td>{{ format_date($p->payment_date) }}</td><td>{{ $p->invoice?->invoice_number ?? ($p->emiPlan?->plan_number ?? 'EMI') }}</td>
                                    <td>{{ $p->mode->label() }}</td><td class="text-end">{{ money($p->amount) }}</td>
                                    <td class="text-end"><a href="{{ route('payments.receipt', $p) }}" target="_blank" class="btn btn-sm btn-icon btn-light" title="Print receipt"><i class="bi bi-printer"></i></a></td></tr>
                            @endforeach</tbody></table></div>
                    @endif
                </div>

                {{-- Visits --}}
                <div class="tab-pane fade" id="tab-visits" role="tabpanel">
                    @forelse ($visits as $v)
                        <a href="{{ route('visits.show', $v) }}" class="list-row text-decoration-none">
                            <span><strong>{{ format_date($v->visit_date) }}</strong> · {{ $v->doctor->name }}<br>
                                <span class="text-muted small">{{ \Illuminate\Support\Str::limit($v->chief_complaint, 100) }}{{ $v->diagnosis ? ' → '.\Illuminate\Support\Str::limit($v->diagnosis, 60) : '' }}</span></span>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </a>
                    @empty
                        @include('partials.empty', ['icon' => 'bi-clipboard2-pulse', 'text' => 'No visits recorded yet.'])
                    @endforelse
                    @can('visits.manage')<div class="mt-3"><a href="{{ route('visits.create', ['patient' => $patient->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Record visit</a></div>@endcan
                </div>

                {{-- EMI --}}
                <div class="tab-pane fade" id="tab-emi" role="tabpanel">
                    @if ($emiPlans->isEmpty())
                        @include('partials.empty', ['icon' => 'bi-calendar2-check', 'text' => 'No EMI plans. Use More → New EMI plan for treatment paid in instalments.'])
                    @else
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>Plan</th><th>Treatment</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Ends</th><th>Status</th></tr></thead>
                            <tbody>@foreach ($emiPlans as $e)
                                <tr><td><a href="{{ route('emi-plans.show', $e) }}" class="fw-semibold">{{ $e->plan_number }}</a></td><td>{{ $e->treatment_name }}</td>
                                    <td class="text-end">{{ money($e->total_amount) }}</td><td class="text-end">{{ money($e->balance_amount) }}</td>
                                    <td>{{ format_date($e->end_date) }}</td><td>@include('emi._status', ['status' => $e->status])</td></tr>
                            @endforeach</tbody></table></div>
                    @endif
                    @can('emi.manage')<div class="mt-3"><a href="{{ route('emi-plans.create', ['patient' => $patient->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>New EMI plan</a></div>@endcan
                </div>

                {{-- Follow-ups --}}
                <div class="tab-pane fade" id="tab-followups" role="tabpanel">
                    @if ($followUps->isEmpty())
                        @include('partials.empty', ['icon' => 'bi-telephone', 'text' => 'No follow-ups scheduled.'])
                    @else
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>Date</th><th>Doctor</th><th>Reason</th><th>Status</th></tr></thead>
                            <tbody>@foreach ($followUps as $f)
                                <tr><td><a href="{{ route('follow-ups.show', $f) }}" class="fw-semibold">{{ format_date($f->follow_up_date) }}</a>{{ $f->time_label ? ' · '.$f->time_label : '' }}</td>
                                    <td>{{ $f->doctor?->name ?? '—' }}</td><td>{{ $f->reason }}</td><td>@include('followups._status', ['followUp' => $f])</td></tr>
                            @endforeach</tbody></table></div>
                    @endif
                    @can('followups.manage')<div class="mt-3"><a href="{{ route('follow-ups.create', ['patient' => $patient->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Set follow-up</a></div>@endcan
                </div>
            </div>
        </div>
    </div>

    {{-- ───────── Side: money, next visit, contact ───────── --}}
    <div class="col-xl-4">
        @can('invoices.view')
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span>What they owe</span>
                @if ($canReceive)<button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#receiveModal">Receive payment</button>@endif</div>
            <div class="card-body">
                @if ($due > 0)
                    <div class="dues-total"><span class="fw-semibold">Total due</span><span class="a">{{ money($due) }}</span></div>
                    @foreach ($dues['invoices'] as $i)
                        <div class="dues-item">
                            <div>
                                <a href="{{ route('invoices.show', $i) }}" class="fw-semibold">{{ $i->invoice_number }}</a>
                                <div class="small text-muted">{{ format_date($i->invoice_date) }} · @include('partials.invoice-status', ['status' => $i->status])
                                    @if ($i->on_emi && $i->emiPlans->first())<a href="{{ route('emi-plans.show', $i->emiPlans->first()) }}" class="chip chip-brand ms-1">on EMI</a>@endif</div>
                            </div>
                            <strong class="text-danger">{{ money($i->balance_amount) }}</strong>
                        </div>
                    @endforeach
                    @foreach ($dues['plans'] as $pl)
                        <div class="dues-item">
                            <div>
                                <a href="{{ route('emi-plans.show', $pl) }}" class="fw-semibold">{{ $pl->plan_number }}</a>
                                <div class="small text-muted">{{ $pl->treatment_name }}@if ($pl->next_due) · next {{ format_date($pl->next_due) }}@endif</div>
                            </div>
                            <div class="text-end"><strong class="text-danger d-block">{{ money($pl->balance_amount) }}</strong>
                                @can('emi.manage')<a href="#" data-receive-plan="{{ $pl->id }}" class="small fw-semibold">Collect instalment →</a>@endcan</div>
                        </div>
                    @endforeach
                @else
                    <div class="dues-clear"><i class="bi bi-check-circle-fill"></i>Nothing is owed. All bills are paid.</div>
                @endif
            </div>
        </div>
        @endcan

        @can('appointments.view')
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Appointments</span>
                @can('appointments.manage')<a href="{{ route('appointments.create', ['patient' => $patient->id]) }}" class="small fw-semibold">+ Book</a>@endcan</div>
            <div class="card-body">
                @forelse ($appointments as $a)
                    <div class="list-row flex-wrap gap-2">
                        <a href="{{ route('appointments.show', $a) }}" class="text-decoration-none text-body"><strong>{{ format_date($a->appointment_date) }}</strong> · {{ $a->time_label }}<br><span class="text-muted small">{{ $a->doctor->name }}@if ($a->reason) · {{ $a->reason }}@endif</span></a>
                        <span class="text-end">
                            @include('appointments._status', ['appointment' => $a])
                            @can('manage', $a)
                                @if ($a->status === \App\Enums\AppointmentStatus::Scheduled)<button type="button" class="btn btn-sm btn-success mt-1 js-confirm-appt" data-url="{{ route('appointments.confirm', $a) }}" data-name="{{ $patient->name }}">Confirm</button>@endif
                                @if ($a->appointment_date->isToday())<button type="button" class="btn btn-sm btn-primary mt-1 js-action" data-url="{{ route('appointments.check-in', $a) }}" data-method="POST" data-title="Check in {{ e($patient->name) }}?" data-text="This opens today's visit with {{ e($a->doctor->name) }}." data-confirm="Yes, check in">Arrived</button>@endif
                            @endcan
                        </span>
                    </div>
                @empty
                    <div class="text-muted small">No appointment booked.</div>
                @endforelse
            </div>
        </div>
        @can('appointments.manage')@include('appointments._modals', ['modes' => \App\Enums\ConfirmationMode::cases()])@endcan
        @endcan

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Next follow-up</span>
                @can('followups.manage')<a href="{{ route('follow-ups.create', ['patient' => $patient->id]) }}" class="small fw-semibold">+ Add</a>@endcan</div>
            <div class="card-body">
                @forelse ($upcoming as $f)
                    <a href="{{ route('follow-ups.show', $f) }}" class="list-row">
                        <span><strong>{{ format_date($f->follow_up_date) }}</strong>{{ $f->time_label ? ' · '.$f->time_label : '' }}<br><span class="text-muted small">{{ $f->reason }}</span></span>
                        @if ($f->follow_up_date->isToday())<span class="badge bg-warning">Today</span>@endif
                    </a>
                @empty
                    <div class="text-muted small">No follow-up booked.</div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header">About</div>
            <div class="card-body">
                <dl class="row mb-0 detail-list small">
                    <dt class="col-5">Date of birth</dt><dd class="col-7">{{ format_date($patient->date_of_birth) }}</dd>
                    <dt class="col-5">Alt. phone</dt><dd class="col-7">{{ $patient->alternate_phone ?? '—' }}</dd>
                    <dt class="col-5">Email</dt><dd class="col-7 text-break">{{ $patient->email ?? '—' }}</dd>
                    <dt class="col-5">Address</dt><dd class="col-7">{{ $patient->address ?? '—' }}</dd>
                    <dt class="col-5">Emergency</dt><dd class="col-7">{{ $patient->emergency_contact_name ?? '—' }}@if ($patient->emergency_contact_phone)<br>{{ $patient->emergency_contact_phone }}@endif</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

{{-- ───────── New bill: slides in from the right ───────── --}}
@if ($canBill)
<div class="offcanvas offcanvas-end" tabindex="-1" id="billDrawer" aria-labelledby="billDrawerTitle">
    <div class="offcanvas-header">
        <div>
            <div class="small text-muted">New bill for</div>
            <h5 class="offcanvas-title" id="billDrawerTitle">{{ $patient->name }} <span class="text-muted fw-normal">· {{ $patient->uhid }}</span></h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        @include('invoices._form', [
            'embedded' => true, 'visit' => null, 'doctorId' => $today['visits']->first()?->doctor_id ?? $lastVisit?->doctor_id,
            'doctors' => $doctors, 'modes' => $billing['modes'], 'hasVisitToday' => $today['visits']->isNotEmpty(), 'previousDue' => $dues['collectable'],
        ])
    </div>
</div>
@endif

@include('patients._receive')
@endsection

@push('scripts')
@if ($canBill)@include('invoices._form-scripts', ['services' => $billing['services'], 'modes' => $billing['modes'], 'doctors' => $doctors])@endif
<script>
(function () {
    const inr = p => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const paise = v => Math.round((parseFloat(v) || 0) * 100);

    // remember the tab across reloads
    const key = 'patientTab{{ $patient->id }}';
    try { const t = sessionStorage.getItem(key); if (t && document.querySelector('[data-bs-target="' + t + '"]')) bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="' + t + '"]')).show(); } catch (e) {}
    document.querySelectorAll('#profileTabs [data-bs-toggle=tab]').forEach(el => el.addEventListener('shown.bs.tab', e => { try { sessionStorage.setItem(key, e.target.dataset.bsTarget); } catch (err) {} }));

    // open the bill panel / receive dialog when asked to
    @if ($canBill && $autoBill) bootstrap.Offcanvas.getOrCreateInstance('#billDrawer').show(); @endif
    @if ($autoReceive && $canReceive) bootstrap.Modal.getOrCreateInstance('#receiveModal').show(); @endif

    // the "pay old dues first" link inside the bill panel
    document.querySelectorAll('[data-open-receive]').forEach(b => b.addEventListener('click', () => {
        bootstrap.Offcanvas.getOrCreateInstance('#billDrawer').hide();
        setTimeout(() => bootstrap.Modal.getOrCreateInstance('#receiveModal').show(), 350);
    }));
})();
</script>
@endpush
