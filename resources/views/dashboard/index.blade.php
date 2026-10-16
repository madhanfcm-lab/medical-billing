@extends('layouts.app')
@section('title', 'Dashboard')
@section('hide-header', '1')

@section('content')
@php
    $hour = (int) now()->format('G');
    $hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $first = \Illuminate\Support\Str::of(auth()->user()->name)->replaceMatches('/^(Dr\.?|Mr\.?|Mrs\.?|Ms\.?)\s+/i', '')->before(' ');
    $trend = $collection_30_days;
    $trendTotal = array_sum($trend['data']);
    $modeTotal = array_sum($collection_by_mode['data']);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div>
        <h1 class="greeting">{{ $hello }}, {{ $first }}</h1>
        <p class="page-sub mb-0">{{ now()->format('l, d-m-Y') }} · here's how the clinic is doing today.</p>
    </div>
</div>

{{-- Quick actions: the things done most at the front desk --}}
<div class="row g-3 mb-4">
    @can('patients.manage')
        <div class="col-6 col-xl-3"><a href="{{ route('patients.create') }}" class="quick-tile primary"><span class="qi"><i class="bi bi-person-plus"></i></span><span><span class="qt d-block">Register patient</span><span class="qs">New patient, then bill</span></span></a></div>
    @endcan
    <div class="col-6 col-xl-3"><a href="#" class="quick-tile js-focus-search"><span class="qi"><i class="bi bi-search"></i></span><span><span class="qt d-block">Find a patient</span><span class="qs">Name, phone or ID</span></span></a></div>
    @can('invoices.create')
        <div class="col-6 col-xl-3"><a href="{{ route('invoices.create') }}" class="quick-tile"><span class="qi"><i class="bi bi-receipt"></i></span><span><span class="qt d-block">New bill</span><span class="qs">Bill and take payment</span></span></a></div>
    @endcan
    @can('appointments.view')
        <div class="col-6 col-xl-3"><a href="{{ route('appointments.index') }}" class="quick-tile"><span class="qi"><i class="bi bi-calendar-event"></i></span><span><span class="qt d-block">Appointments today</span><span class="qs">{{ $cards['today_appointments'] }} booked{{ $cards['appointments_to_confirm'] > 0 ? ' · '.$cards['appointments_to_confirm'].' to confirm' : '' }}</span></span></a></div>
    @endcan
    @can('followups.view')
        <div class="col-6 col-xl-3"><a href="{{ route('follow-ups.index') }}" class="quick-tile"><span class="qi"><i class="bi bi-telephone-outbound"></i></span><span><span class="qt d-block">Follow-ups today</span><span class="qs">{{ $cards['today_followups'] }} scheduled</span></span></a></div>
    @endcan
    @cannot('patients.manage')
        @can('attendance.manage')
        <div class="col-6 col-xl-3"><a href="{{ route('attendance.index') }}" class="quick-tile"><span class="qi"><i class="bi bi-person-check"></i></span><span><span class="qt d-block">Take attendance</span><span class="qs">Mark today's staff</span></span></a></div>
        @endcan
    @endcannot
</div>

{{-- Numbers --}}
@php
    $tiles = [
        ['key' => 'today_collection', 'label' => "Today's collection", 'icon' => 'bi-cash-coin', 'tone' => 'success', 'money' => true],
        ['key' => 'month_collection', 'label' => 'This month', 'icon' => 'bi-calendar-check', 'tone' => 'primary', 'money' => true],
        ['key' => 'total_outstanding', 'label' => 'Total outstanding', 'icon' => 'bi-hourglass-split', 'tone' => 'danger', 'money' => true],
        ['key' => 'today_patients', 'label' => "Today's patients", 'icon' => 'bi-people', 'tone' => 'info'],
        ['key' => 'staff_present', 'label' => 'Staff present', 'icon' => 'bi-person-check', 'tone' => 'secondary'],
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach ($tiles as $t)
        <div class="col-6 col-lg-4 col-xl"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icon bg-{{ $t['tone'] }}-subtle text-{{ $t['tone'] }}"><i class="bi {{ $t['icon'] }}"></i></span>
            <div><div class="stat-label">{{ $t['label'] }}</div><div class="stat-value">{{ !empty($t['money']) ? money($cards[$t['key']]) : $cards[$t['key']] }}</div></div>
        </div></div></div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    {{-- Needs attention --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Needs attention</div>
            <div class="card-body d-grid gap-2">
                @can('emi.view')
                <a href="{{ route('emi-plans.dues') }}" class="attn {{ $cards['overdue_emis'] ? 'bad' : 'ok' }}"><span class="n">{{ $cards['overdue_emis'] }}</span><span><strong class="d-block">Overdue EMI instalments</strong><span class="small text-muted">{{ $cards['overdue_emis'] ? 'Send a WhatsApp reminder' : 'Everyone is up to date' }}</span></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
                @endcan
                @can('followups.view')
                <a href="{{ route('follow-ups.index', ['tab' => 'missed']) }}" class="attn {{ $cards['missed_followups'] ? 'warn' : 'ok' }}"><span class="n">{{ $cards['missed_followups'] }}</span><span><strong class="d-block">Missed follow-ups</strong><span class="small text-muted">{{ $cards['missed_followups'] ? 'Call or reschedule' : 'None missed' }}</span></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
                @endcan
                @can('invoices.view')
                <a href="{{ route('invoices.index') }}" class="attn {{ $cards['unpaid_bills'] ? 'warn' : 'ok' }}"><span class="n">{{ $cards['unpaid_bills'] }}</span><span><strong class="d-block">Bills with a balance</strong><span class="small text-muted">{{ $cards['unpaid_bills'] ? 'Total outstanding '.money($cards['total_outstanding']) : 'All bills are paid' }}</span></span><i class="bi bi-chevron-right ms-auto text-muted"></i></a>
                @endcan
            </div>
        </div>
    </div>
    {{-- Today's patients --}}
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Today's patients</span><span class="small text-muted fw-normal">{{ $cards['today_patients'] }} seen</span></div>
            <div class="card-body">
                @forelse ($today_visits as $v)
                    <a href="{{ route('patients.show', $v->patient_id) }}" class="list-row">
                        <span class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-tone-{{ $v->patient_id % 6 + 1 }}">{{ strtoupper(mb_substr($v->patient->name, 0, 1)) }}</span>
                            <span><strong class="d-block">{{ $v->patient->name }}</strong><span class="small text-muted">{{ $v->patient->uhid }} · {{ $v->doctor->name }}</span></span>
                        </span>
                        <span class="small text-muted d-none d-md-inline">{{ \Illuminate\Support\Str::limit($v->chief_complaint, 40) }}</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </a>
                @empty
                    @include('partials.empty', ['icon' => 'bi-people', 'text' => 'No patients seen yet today. Search for a patient or register a new one to begin.'])
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Collection · last 30 days</span>
                <span class="small text-muted fw-normal">Total {{ money($trendTotal) }}</span>
            </div>
            <div class="card-body">
                @if ($trendTotal > 0)
                    <div class="chart-box"><canvas id="chartCollection" role="img" aria-label="Daily collection for the last 30 days"></canvas></div>
                @else
                    @include('partials.empty', ['icon' => 'bi-graph-up', 'text' => 'No payments in the last 30 days yet. The chart appears once money is collected.'])
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>By payment mode</span>
                <span class="small text-muted fw-normal">Last 30 days</span>
            </div>
            <div class="card-body">
                @if ($modeTotal > 0)
                    <div class="chart-box"><canvas id="chartModes" role="img" aria-label="Collection by payment mode"></canvas></div>
                @else
                    @include('partials.empty', ['icon' => 'bi-pie-chart', 'text' => 'No payments to split by mode yet.'])
                @endif
            </div>
        </div>
    </div>
</div>

@can('appointments.view')
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center"><span>Today's appointments</span><a href="{{ route('appointments.index') }}" class="small fw-semibold">All</a></div>
    <div class="card-body">
        @forelse ($todays_appointments as $a)
            <div class="list-row">
                <span><strong>{{ $a->time_label }}</strong> · <a href="{{ route('patients.show', $a->patient_id) }}" class="fw-semibold">{{ $a->patient->name }}</a><br>
                    <span class="small text-muted">{{ $a->doctor->name }}@if ($a->reason) · {{ \Illuminate\Support\Str::limit($a->reason, 40) }}@endif</span></span>
                <span class="text-end">@include('appointments._status', ['appointment' => $a])</span>
            </div>
        @empty
            @include('partials.empty', ['icon' => 'bi-calendar-event', 'text' => 'No appointments today.'])
        @endforelse
    </div>
</div>
@endcan

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Today's follow-ups</span>@can('followups.view')<a href="{{ route('follow-ups.index') }}" class="small fw-semibold">All</a>@endcan</div>
            <div class="card-body">
                @forelse ($todays_followups as $f)
                    <div class="list-row">
                        <span><a href="{{ route('patients.show', $f->patient_id) }}" class="fw-semibold">{{ $f->patient->name }}</a><br>
                            <span class="small text-muted">{{ $f->time_label ?? 'Any time' }} · {{ $f->doctor?->name ?? '—' }} · {{ \Illuminate\Support\Str::limit($f->reason, 28) }}</span></span>
                        <a href="{{ \App\Support\Whatsapp::link($f->patient->phone, \App\Support\Whatsapp::followUpReminder($f)) }}" target="_blank" rel="noopener" class="btn btn-sm btn-icon btn-outline-success" title="WhatsApp reminder"><i class="bi bi-whatsapp"></i></a>
                    </div>
                @empty
                    @include('partials.empty', ['icon' => 'bi-telephone', 'text' => 'No follow-ups scheduled for today.'])
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Overdue installments</span>@can('emi.view')<a href="{{ route('emi-plans.dues') }}" class="small fw-semibold">All</a>@endcan</div>
            <div class="card-body">
                @forelse ($overdue_installments as $inst)
                    <div class="list-row">
                        <span><a href="{{ route('emi-plans.show', $inst->emi_plan_id) }}" class="fw-semibold">{{ $inst->plan->patient->name }}</a><br>
                            <span class="small text-danger">#{{ $inst->installment_no }} · due {{ format_date($inst->due_date) }} · {{ (int) $inst->due_date->diffInDays(today(), true) }}d overdue</span></span>
                        <span class="text-end">{{ money($inst->balance) }}<br>
                            <a href="{{ \App\Support\Whatsapp::link($inst->plan->patient->phone, \App\Support\Whatsapp::emiReminder($inst)) }}" target="_blank" rel="noopener" class="small text-success fw-semibold"><i class="bi bi-whatsapp"></i> Remind</a></span>
                    </div>
                @empty
                    @include('partials.empty', ['icon' => 'bi-check2-circle', 'text' => 'No overdue installments. 🎉'])
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Recent bills</span>@can('invoices.view')<a href="{{ route('invoices.index') }}" class="small fw-semibold">All</a>@endcan</div>
            <div class="card-body">
                @forelse ($recent_invoices as $inv)
                    <a href="{{ route('invoices.show', $inv) }}" class="list-row">
                        <span><strong>{{ $inv->invoice_number }}</strong><br><span class="small text-muted">{{ $inv->patient->name }} · {{ format_date($inv->invoice_date) }}</span></span>
                        <span class="text-end">{{ money($inv->grand_total) }}<br>@include('partials.invoice-status', ['status' => $inv->status])</span>
                    </a>
                @empty
                    @include('partials.empty', ['icon' => 'bi-receipt', 'text' => 'No invoices yet.'])
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const collection = @json($collection_30_days);
const modes = @json($collection_by_mode);
const inr = n => '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const modeColours = { 'Cash': '#0d9488', 'UPI': '#2563eb', 'Card': '#f59e0b', 'Bank Transfer': '#7c3aed', 'Cheque': '#64748b' };
Chart.defaults.font.family = "'Inter', system-ui, sans-serif"; Chart.defaults.color = '#64748b';

$('.js-focus-search').on('click', e => { e.preventDefault(); $('#globalPatientSearch').select2('open'); });

const lineEl = document.getElementById('chartCollection');
if (lineEl) {
    const ctx = lineEl.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 280); grad.addColorStop(0, 'rgba(13,148,136,.22)'); grad.addColorStop(1, 'rgba(13,148,136,0)');
    window.collectionChart = new Chart(lineEl, {
        type: 'line',
        data: { labels: collection.labels, datasets: [{ label: 'Collected', data: collection.data, borderColor: '#0d9488', backgroundColor: grad, fill: true, tension: .3, borderWidth: 2.5, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#0d9488' }] },
        options: {
            maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, callbacks: { title: c => c[0].label, label: c => inr(c.parsed.y) } } },
            scales: { y: { beginAtZero: true, min: 0, grid: { color: '#eef2f6' }, border: { display: false }, ticks: { callback: v => '₹' + v.toLocaleString('en-IN') } }, x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } } },
        },
    });
}
const pieEl = document.getElementById('chartModes');
if (pieEl) {
    const sum = modes.data.reduce((a, b) => a + b, 0);
    window.modeChart = new Chart(pieEl, {
        type: 'doughnut',
        data: { labels: modes.labels, datasets: [{ data: modes.data, backgroundColor: modes.labels.map(l => modeColours[l] || '#94a3b8'), borderWidth: 3, borderColor: '#fff', hoverOffset: 4 }] },
        options: {
            maintainAspectRatio: false, cutout: '66%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                tooltip: { backgroundColor: '#0f172a', padding: 10, cornerRadius: 8, callbacks: { label: c => ' ' + c.label + ': ' + inr(c.parsed) + ' (' + Math.round(c.parsed / sum * 100) + '%)' } } },
        },
    });
}

function updateDashboardCharts(themeData) {
    const isDark = themeData ? themeData.isDark : document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const computed = getComputedStyle(document.documentElement);
    const lineSoft = computed.getPropertyValue('--line-soft').trim() || (isDark ? 'rgba(255,255,255,0.06)' : '#eef2f6');
    const ink500 = computed.getPropertyValue('--ink-500').trim() || '#64748b';
    const surface = computed.getPropertyValue('--surface').trim() || (isDark ? '#121826' : '#ffffff');
    const tooltipBg = isDark ? '#1e293b' : '#0f172a';

    Chart.defaults.color = ink500;

    if (window.collectionChart) {
        window.collectionChart.options.scales.y.grid.color = lineSoft;
        window.collectionChart.options.plugins.tooltip.backgroundColor = tooltipBg;
        window.collectionChart.update();
    }
    if (window.modeChart) {
        window.modeChart.data.datasets[0].borderColor = surface;
        window.modeChart.options.plugins.tooltip.backgroundColor = tooltipBg;
        window.modeChart.update();
    }
}

window.addEventListener('clinic-theme-changed', function (e) {
    updateDashboardCharts(e.detail);
});
updateDashboardCharts();
</script>
@endpush
