@extends('layouts.app')
@section('title', $plan->plan_number)
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('emi-plans.index') }}">EMI Plans</a></li>
    <li class="breadcrumb-item active">{{ $plan->plan_number }}</li>
@endsection
@section('actions')
    @can('followups.manage')<a href="{{ route('follow-ups.create', ['emi_plan' => $plan->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-telephone-plus me-1"></i>Set follow-up</a>@endcan
    @can('collect', $plan)<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#collectModal"><i class="bi bi-cash-coin me-1"></i>Collect payment</button>@endcan
    @can('reschedule', $plan)<button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rescheduleModal"><i class="bi bi-calendar2-week me-1"></i>Reschedule</button>@endcan
    @can('markDefaulted', $plan)
        <button class="btn btn-outline-warning js-action" data-url="{{ route('emi-plans.default', $plan) }}" data-method="POST"
                data-title="Mark as defaulted?" data-text="Use this when the patient has stopped paying. Payments can still be collected." data-confirm="Yes, mark defaulted">Mark defaulted</button>
    @endcan
    @can('reactivate', $plan)
        <button class="btn btn-outline-success js-action" data-url="{{ route('emi-plans.reactivate', $plan) }}" data-method="POST"
                data-title="Reactivate plan?" data-text="The plan will be Active again." data-confirm="Yes, reactivate">Reactivate</button>
    @endcan
    @can('cancel', $plan)<button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle me-1"></i>Cancel plan</button>@endcan
@endsection

@section('content')
@php
    $overdue = to_paise($plan->overdueAmount());
    $status = $plan->status;
    $nextDue = $plan->installments->first(fn ($i) => to_paise($i->balance) > 0);
    $totalPaid = to_paise($plan->down_payment) + to_paise($plan->paid_amount);
@endphp

@if (session('receipt_id'))
    <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check-circle me-1"></i>Payment recorded.</span>
        <span>
            <a class="btn btn-sm btn-success" target="_blank" href="{{ route('payments.receipt', session('receipt_id')) }}"><i class="bi bi-printer me-1"></i>Print receipt</a>
            <a class="btn btn-sm btn-outline-success" href="{{ route('payments.receipt.pdf', session('receipt_id')) }}">PDF</a>
        </span>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
@if ($status === \App\Enums\EmiPlanStatus::Cancelled)
    <div class="alert alert-secondary"><strong>Cancelled</strong> on {{ format_datetime($plan->cancelled_at) }} by {{ $plan->cancelledBy?->name ?? '—' }} — “{{ $plan->cancel_reason }}”.
        Payments received ({{ money($totalPaid / 100) }}) stay on record.</div>
@elseif ($status === \App\Enums\EmiPlanStatus::Completed)
    <div class="alert alert-success"><i class="bi bi-patch-check me-1"></i><strong>Fully paid.</strong> This plan is complete.</div>
@elseif ($status === \App\Enums\EmiPlanStatus::Defaulted)
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i><strong>Defaulted</strong> since {{ format_date($plan->defaulted_at) }}. Balance {{ money($plan->balance_amount) }} is still owed.</div>
@endif
@if ($plan->status->isOpen() && $plan->isBehind())
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        @if ($overdue > 0)<strong>Behind schedule:</strong> {{ money($overdue / 100) }} is overdue.@endif
        @if ($plan->end_date->lt(today()))The plan end date ({{ format_date($plan->end_date) }}) has passed with {{ money($plan->balance_amount) }} still unpaid.@endif
    </div>
@elseif ($projectedEnd && $projectedEnd->gt($plan->end_date))
    <div class="alert alert-warning">
        <i class="bi bi-hourglass-split me-1"></i><strong>May not finish in time.</strong> At the current pace of payments the plan would finish around
        <strong>{{ format_date($projectedEnd) }}</strong>, after the planned end date of {{ format_date($plan->end_date) }}.
    </div>
@endif

<div class="row g-3 mb-3">
    @foreach ([
        ['Total amount', money($plan->total_amount), 'bi-receipt', 'primary'],
        ['Down payment', money($plan->down_payment), 'bi-box-arrow-in-down', 'info'],
        ['Paid on instalments', money($plan->paid_amount), 'bi-cash-coin', 'success'],
        ['Balance', money($plan->balance_amount), 'bi-hourglass-split', to_paise($plan->balance_amount) > 0 ? 'danger' : 'success'],
    ] as [$label, $value, $icon, $tone])
        <div class="col-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <span class="stat-icon bg-{{ $tone }}-subtle text-{{ $tone }}"><i class="bi {{ $icon }}"></i></span>
            <div><div class="stat-label">{{ $label }}</div><div class="stat-value">{{ $value }}</div></div>
        </div></div></div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Instalment schedule</span> @include('emi._status', ['status' => $status])</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($plan->installments as $i)
                        @php $st = $i->effective_status; @endphp
                        <tr @class(['table-danger' => $st === \App\Enums\InstallmentStatus::Overdue && $plan->status->isOpen()])>
                            <td>{{ $i->installment_no }}</td>
                            <td>{{ format_date($i->due_date) }}</td>
                            <td class="text-end">{{ money($i->amount) }}</td>
                            <td class="text-end">{{ money($i->paid_amount) }}</td>
                            <td class="text-end">{{ money($i->balance) }}</td>
                            <td>@if ($st === \App\Enums\InstallmentStatus::Overdue && ! $plan->status->isOpen())<span class="badge bg-secondary">Unpaid</span>@else<span class="badge {{ $st->badge() }}">{{ $st->label() }}</span>@endif
                                @if ($i->paid_on && $st === \App\Enums\InstallmentStatus::Paid)<div class="small text-muted">{{ format_date($i->paid_on) }}</div>@endif</td>
                            <td class="text-end">
                                @if ($plan->status->isOpen() && to_paise($i->balance) > 0)
                                    @php $i->setRelation('plan', $plan); @endphp
                                    <a href="{{ \App\Support\Whatsapp::link($plan->patient->phone, \App\Support\Whatsapp::emiReminder($i)) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="WhatsApp reminder"><i class="bi bi-whatsapp"></i></a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Payments</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Receipt</th><th>Date</th><th>Mode</th><th class="text-end">Amount</th><th>Applied to</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($plan->payments as $p)
                        <tr>
                            <td>{{ $p->receipt_number }}</td><td>{{ format_date($p->payment_date) }}</td>
                            <td>{{ $p->mode->label() }}@if ($p->reference_no)<div class="small text-muted">{{ $p->reference_no }}</div>@endif</td>
                            <td class="text-end">{{ money($p->amount) }}</td>
                            <td class="small">
                                @forelse ($p->allocations as $a)
                                    <div>#{{ $a->installment->installment_no }}: {{ money($a->amount) }}</div>
                                @empty
                                    <span class="text-muted">{{ $p->notes ?: '—' }}</span>
                                @endforelse
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('payments.receipt', $p) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Receipt"><i class="bi bi-printer"></i></a>
                                <a href="{{ route('payments.receipt.pdf', $p) }}" class="btn btn-sm btn-outline-secondary" title="PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6">@include('partials.empty', ['icon' => 'bi-cash-coin', 'text' => 'No payments received yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($plan->reschedules->isNotEmpty())
            <div class="card">
                <div class="card-header">Reschedule history</div>
                <div class="list-group list-group-flush">
                    @foreach ($plan->reschedules as $r)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between"><strong>{{ format_datetime($r->created_at) }}</strong><span class="text-muted small">by {{ $r->creator?->name ?? '—' }}</span></div>
                            <div class="small">Balance {{ money($r->balance_at_time) }} re-planned as {{ $r->new_months }} instalment(s) of about {{ money($r->new_monthly_amount) }}, due on day {{ $r->new_due_day }}. Reason: {{ $r->reason }}</div>
                            <details class="small mt-1"><summary class="text-muted">Previous schedule</summary>
                                <table class="table table-sm mb-0 mt-1"><thead><tr><th>#</th><th>Due</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Status</th></tr></thead><tbody>
                                @foreach ($r->old_schedule as $o)
                                    <tr><td>{{ $o['no'] }}</td><td>{{ format_date($o['due_date']) }}</td><td class="text-end">{{ money($o['amount']) }}</td><td class="text-end">{{ money($o['paid_amount']) }}</td><td>{{ ucwords(str_replace('_', ' ', $o['status'])) }}</td></tr>
                                @endforeach
                                </tbody></table>
                            </details>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-5">Patient</dt><dd class="col-7"><a href="{{ route('patients.show', $plan->patient_id) }}">{{ $plan->patient->name }}</a><div class="small text-muted">{{ $plan->patient->phone }}</div></dd>
                <dt class="col-5">Treatment</dt><dd class="col-7">{{ $plan->treatment_name }}</dd>
                @if ($plan->invoice)<dt class="col-5">Invoice</dt><dd class="col-7"><a href="{{ route('invoices.show', $plan->invoice) }}">{{ $plan->invoice->invoice_number }}</a></dd>@endif
                @if ($plan->visit)<dt class="col-5">Visit</dt><dd class="col-7"><a href="{{ route('visits.show', $plan->visit) }}">{{ format_date($plan->visit->visit_date) }}</a></dd>@endif
                <dt class="col-5">To finance</dt><dd class="col-7">{{ money($plan->financed_amount) }}</dd>
                <dt class="col-5">Instalments</dt><dd class="col-7">{{ $plan->months }} × about {{ money($plan->monthly_amount) }}</dd>
                <dt class="col-5">Due day</dt><dd class="col-7">{{ $plan->due_day }}{{ in_array($plan->due_day % 100, [11, 12, 13]) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$plan->due_day % 10] ?? 'th') }} of each month</dd>
                <dt class="col-5">Started</dt><dd class="col-7">{{ format_date($plan->start_date) }}</dd>
                <dt class="col-5">Plan ends</dt><dd class="col-7 fw-semibold {{ $plan->isBehind() ? 'text-danger' : '' }}">{{ format_date($plan->end_date) }}</dd>
                @if ($nextDue && $plan->status->isOpen())<dt class="col-5">Next due</dt><dd class="col-7">{{ format_date($nextDue->due_date) }} · {{ money($nextDue->balance) }}</dd>@endif
                @if ($overdue > 0 && $plan->status->isOpen())<dt class="col-5">Overdue</dt><dd class="col-7 text-danger fw-semibold">{{ money($overdue / 100) }}</dd>@endif
            </dl>
            @if ($plan->notes)<hr><div class="small text-muted">Notes</div><div>{!! nl2br(e($plan->notes)) !!}</div>@endif
        </div></div>
        @if ($audit) @include('audit._trail', ['logs' => $audit]) @endif
    </div>
</div>

@can('collect', $plan)
<div class="modal fade" id="collectModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" action="{{ route('emi-plans.collect', $plan) }}" class="modal-content" novalidate data-loading-form data-confirm-fn="confirmCollect">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Collect payment — {{ $plan->plan_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body vstack gap-3">
            <div class="alert alert-light border mb-0">Plan balance: <strong>{{ money($plan->balance_amount) }}</strong>
                @if ($nextDue)<br><span class="small text-muted">Next instalment #{{ $nextDue->installment_no }}: {{ money($nextDue->balance) }}, due {{ format_date($nextDue->due_date) }}</span>@endif</div>
            <div>
                <label class="form-label" for="c_amount">Amount received (₹) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="0.01" max="{{ $plan->balance_amount }}" name="amount" id="c_amount" value="{{ old('amount', $nextDue?->balance) }}" required class="form-control form-control-lg @error('amount') is-invalid @enderror">
                <div class="invalid-feedback">{{ $errors->first('amount') ?: 'Enter an amount up to the plan balance.' }}</div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="c_next">Next instalment</button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="c_settle">Settle in full ({{ money($plan->balance_amount) }})</button>
                </div>
                <div class="form-text">Any amount is accepted. It is applied to the oldest unpaid instalment first; extra carries to the next ones.</div>
                <div id="c_alloc" class="small mt-2"></div>
            </div>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label" for="c_mode">Mode <span class="text-danger">*</span></label>
                    <select name="mode" id="c_mode" class="form-select" required>@foreach ($modes as $v => $l)<option value="{{ $v }}" @selected(old('mode', 'cash') === $v)>{{ $l }}</option>@endforeach</select>
                </div>
                <div class="col-6"><label class="form-label" for="c_date">Date</label>
                    <input type="date" name="payment_date" id="c_date" value="{{ old('payment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="form-control"></div>
            </div>
            <div id="c_refWrap" class="d-none">
                <label class="form-label" for="c_ref">Reference no. <span class="text-danger">*</span></label>
                <input name="reference_no" id="c_ref" value="{{ old('reference_no') }}" maxlength="100" class="form-control @error('reference_no') is-invalid @enderror" placeholder="UPI ref / card slip / cheque no.">
                <div class="invalid-feedback">{{ $errors->first('reference_no') ?: 'Reference number is required for non-cash payments.' }}</div>
            </div>
            <div><label class="form-label" for="c_notes">Note</label><input name="notes" id="c_notes" value="{{ old('notes') }}" maxlength="255" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-success">Record payment</button></div>
    </form>
</div></div>
@endcan

@can('reschedule', $plan)
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg">
    <form method="POST" action="{{ route('emi-plans.reschedule', $plan) }}" class="modal-content" id="rescheduleForm" novalidate data-loading-form data-confirm-fn="confirmReschedule">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Reschedule balance — {{ money($plan->balance_amount) }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-4">
                <div class="col-md-6 vstack gap-3">
                    <div class="btn-group w-100">
                        <input type="radio" class="btn-check" name="calc_by" id="r_by_months" value="months" @checked(old('calc_by', 'months') === 'months')><label class="btn btn-outline-primary btn-sm" for="r_by_months">By months</label>
                        <input type="radio" class="btn-check" name="calc_by" id="r_by_monthly" value="monthly" @checked(old('calc_by') === 'monthly')><label class="btn btn-outline-primary btn-sm" for="r_by_monthly">By monthly amount</label>
                    </div>
                    <div><label class="form-label">Months</label><input type="number" name="months" min="1" max="60" value="{{ old('months', max($plan->months - $plan->installments->filter(fn ($i) => to_paise($i->balance) <= 0)->count(), 1)) }}" class="form-control"></div>
                    <div><label class="form-label">Monthly amount (₹)</label><input type="number" name="monthly_amount" min="0.01" step="0.01" value="{{ old('monthly_amount') }}" class="form-control"></div>
                    <div><label class="form-label">Start date</label><input type="date" name="start_date" value="{{ old('start_date', today()->toDateString()) }}" required class="form-control"></div>
                    <div><label class="form-label">Due day of month</label><input type="number" name="due_day" min="1" max="31" value="{{ old('due_day', $plan->due_day) }}" required class="form-control"></div>
                    <div><label class="form-label">Reason <span class="text-danger">*</span></label>
                        <input name="reason" maxlength="255" minlength="5" required value="{{ old('reason') }}" class="form-control @error('reason') is-invalid @enderror" placeholder="e.g. Patient requested longer period">
                        <div class="invalid-feedback">{{ $errors->first('reason') ?: 'A reason (at least 5 characters) is required.' }}</div></div>
                </div>
                <div class="col-md-6">
                    <div id="pvMsg" class="alert alert-light border">Fill in the details to see the new schedule.</div>
                    <div id="pvSummary" class="row g-2 mb-2 d-none text-center">
                        <div class="col-4"><div class="stat-label">Instalments</div><div class="fw-bold" id="sCount">—</div></div>
                        <div class="col-4"><div class="stat-label">Monthly</div><div class="fw-bold" id="sMonthly">—</div></div>
                        <div class="col-4"><div class="stat-label">Ends</div><div class="fw-bold" id="sEnd">—</div></div>
                        <span class="d-none" id="sFirst"></span>
                    </div>
                    <div class="table-responsive" style="max-height:300px"><table class="table table-sm mb-0" id="pvTable"><thead class="table-light"><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th></tr></thead><tbody></tbody></table></div>
                    <div class="form-text mt-2">Paid instalments stay as they are. The old schedule is saved in the history.</div>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Reschedule</button></div>
    </form>
</div></div>
@endcan

@can('cancel', $plan)
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" action="{{ route('emi-plans.cancel', $plan) }}" class="modal-content" novalidate data-loading-form data-confirm-fn="confirmCancel">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Cancel {{ $plan->plan_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if ($totalPaid > 0)<div class="alert alert-warning">{{ money($totalPaid / 100) }} has already been received. It stays on record; refund manually if needed.</div>@endif
            <label class="form-label" for="reason">Reason <span class="text-danger">*</span></label>
            <textarea name="reason" id="reason" rows="3" minlength="5" maxlength="255" required class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
            <div class="invalid-feedback">{{ $errors->first('reason') ?: 'A reason (at least 5 characters) is required.' }}</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep plan</button><button class="btn btn-danger">Cancel plan</button></div>
    </form>
</div></div>
@endcan
@endsection

@push('scripts')
<script>
window.EMI_CFG = { previewUrl: @json(route('emi-plans.preview')) };
</script>
<script src="{{ asset('js/emi-form.js') }}"></script>
<script>
(function () {
    const inr = n => '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const open = @json($openInstallments);   // [{no, balance}] oldest first
    const planBalance = {{ (float) $plan->balance_amount }};
    const nextBalance = open.length ? open[0].balance : 0;

    // ---- collect payment ---------------------------------------------------
    function alloc() {
        let left = Math.round((parseFloat($('#c_amount').val()) || 0) * 100), html = [];
        for (const i of open) {
            if (left <= 0) break;
            const take = Math.min(left, Math.round(i.balance * 100));
            html.push('#' + i.no + ' ← ' + inr(take / 100) + (take < Math.round(i.balance * 100) ? ' (part)' : ''));
            left -= take;
        }
        $('#c_alloc').html(html.length ? '<span class="text-muted">This will cover:</span> ' + html.join(' · ') : '');
    }
    $('#c_amount').on('input', alloc);
    $('#c_next').on('click', () => { $('#c_amount').val(nextBalance.toFixed(2)); alloc(); });
    $('#c_settle').on('click', () => { $('#c_amount').val(planBalance.toFixed(2)); alloc(); });
    const refresh = () => $('#c_refWrap').toggleClass('d-none', $('#c_mode').val() === 'cash').find('input').prop('required', $('#c_mode').val() !== 'cash');
    $('#c_mode').on('change', refresh); refresh(); alloc();

    window.confirmCollect = f => {
        const amt = parseFloat(f.amount.value) || 0, settle = Math.abs(amt - planBalance) < 0.005;
        return { title: settle ? 'Settle the plan in full?' : 'Record this payment?', icon: 'question', confirmText: 'Yes, record payment',
            text: inr(amt) + ' by ' + f.mode.selectedOptions[0].text + (settle ? ' — this will complete the plan.' : '.') };
    };
    window.confirmCancel = () => ({ title: 'Cancel this EMI plan?', text: 'Remaining instalments will no longer be collected.', confirmText: 'Yes, cancel plan' });
    window.confirmReschedule = () => ({ title: 'Reschedule the remaining balance?', text: 'The old schedule is kept in the history.', confirmText: 'Yes, reschedule', icon: 'question' });

    // ---- reschedule preview -----------------------------------------------
    if ($('#rescheduleForm').length) {
        const $f = $('#rescheduleForm');
        EmiPreview.bind($f, { amount: () => planBalance, firstNumber: {{ (int) $plan->installments->max('installment_no') + 1 }} });
        $('#rescheduleModal').on('shown.bs.modal', () => $f.find('[name=months]').trigger('change'));
    }

    @if ($errors->has('amount') || $errors->has('reference_no') || $errors->has('mode') || request()->boolean('collect')) new bootstrap.Modal('#collectModal').show(); @endif
    @if ($errors->has('reason') && old('calc_by') !== null) new bootstrap.Modal('#rescheduleModal').show(); @elseif ($errors->has('reason')) new bootstrap.Modal('#cancelModal').show(); @endif
    @if ($errors->has('months') || $errors->has('monthly_amount') || $errors->has('start_date') || $errors->has('due_day')) new bootstrap.Modal('#rescheduleModal').show(); @endif
})();
</script>
@endpush
