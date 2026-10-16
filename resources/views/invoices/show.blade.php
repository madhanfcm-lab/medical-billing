@extends('layouts.app')
@section('title', $invoice->invoice_number)
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('invoices.index') }}">Bills</a></li>
    <li class="breadcrumb-item active">{{ $invoice->invoice_number }}</li>
@endsection
@section('actions')
    <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i>Print</a>
    <a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    @if ($invoice->canCollect())
        @can('emi.manage')<a href="{{ route('emi-plans.create', ['invoice' => $invoice->id]) }}" class="btn btn-outline-primary"><i class="bi bi-calendar2-plus me-1"></i>Create EMI plan</a>@endcan
    @endif
    @can('collect', $invoice)
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#collectModal"><i class="bi bi-cash-coin me-1"></i>Collect due</button>
    @endcan
    @can('cancel', $invoice)
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle me-1"></i>Cancel</button>
    @endcan
@endsection

@section('content')
@if (session('receipt_id'))
    <div class="alert alert-success d-flex justify-content-between align-items-center">
        <span><i class="bi bi-check-circle me-1"></i>Payment recorded.</span>
        <span>
            <a class="btn btn-sm btn-success" target="_blank" href="{{ route('payments.receipt', session('receipt_id')) }}"><i class="bi bi-printer me-1"></i>Print receipt</a>
            <a class="btn btn-sm btn-outline-success" href="{{ route('payments.receipt.pdf', session('receipt_id')) }}">PDF</a>
        </span>
    </div>
@endif
@php $openPlan = $invoice->emiPlans()->open()->first(); @endphp
@if ($openPlan)
    <div class="alert alert-info"><i class="bi bi-calendar2-check me-1"></i>This invoice is being paid through EMI plan
        <a href="{{ route('emi-plans.show', $openPlan) }}" class="alert-link">{{ $openPlan->plan_number }}</a>. Collect payments on the plan.</div>
@endif
@if ($invoice->isCancelled())
    <div class="alert alert-secondary">
        <strong>Cancelled</strong> on {{ format_datetime($invoice->cancelled_at) }} by {{ $invoice->cancelledBy?->name ?? '—' }} — “{{ $invoice->cancel_reason }}”
        @if (to_paise($invoice->paid_amount) > 0)
            <div class="mt-1 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ money($invoice->paid_amount) }} had been collected on this invoice. Any refund must be handled manually.</div>
        @endif
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Items</span> @include('partials.invoice-status', ['status' => $invoice->status])
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Service</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Disc.</th><th class="text-end">GST</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td>{{ $loop->iteration }}</td><td>{{ $item->description }}</td><td class="text-end">{{ $item->quantity }}</td>
                            <td class="text-end">@if ($item->list_price !== null && to_paise($item->unit_price) < to_paise($item->list_price))<del class="text-muted small d-block">{{ money($item->list_price) }}</del>@endif{{ money($item->unit_price) }}</td><td class="text-end">{{ money($item->discount_amount) }}</td>
                            <td class="text-end">{{ rtrim(rtrim($item->gst_percent, '0'), '.') }}%</td><td class="text-end">{{ money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Payments</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Receipt</th><th>Date</th><th>Mode</th><th>Reference</th><th class="text-end">Amount</th><th class="text-end">Balance after</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($invoice->payments as $p)
                        <tr>
                            <td>{{ $p->receipt_number }}</td><td>{{ format_date($p->payment_date) }}</td><td>{{ $p->mode->label() }}</td>
                            <td>{{ $p->reference_no ?? '—' }}</td><td class="text-end">{{ money($p->amount) }}</td><td class="text-end">{{ money($p->balance_after) }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('payments.receipt', $p) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Receipt"><i class="bi bi-printer"></i></a>
                                <a href="{{ route('payments.receipt.pdf', $p) }}" class="btn btn-sm btn-outline-secondary" title="Receipt PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">@include('partials.empty', ['icon' => 'bi-cash-coin', 'text' => 'No payments received yet.'])</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <dl class="row mb-0 detail-list">
                <dt class="col-5">Patient</dt><dd class="col-7"><a href="{{ route('patients.show', $invoice->patient_id) }}">{{ $invoice->patient->name }}</a><div class="small text-muted">{{ $invoice->patient->uhid }}</div></dd>
                <dt class="col-5">Date</dt><dd class="col-7">{{ format_date($invoice->invoice_date) }}</dd>
                <dt class="col-5">Doctor</dt><dd class="col-7">{{ $invoice->doctor?->name ?? '—' }}</dd>
                @if ($invoice->visit)<dt class="col-5">Visit</dt><dd class="col-7"><a href="{{ route('visits.show', $invoice->visit_id) }}">{{ format_date($invoice->visit->visit_date) }}</a></dd>@endif
            </dl>
        </div></div>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Subtotal</span><span>{{ money($invoice->subtotal) }}</span></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Discount @if ($invoice->discount_type === 'percent')({{ rtrim(rtrim($invoice->discount_value, '0'), '.') }}%)@endif</span><span>− {{ money($invoice->discount_amount) }}</span></div>
            @if ($invoice->discount_reason)<div class="small text-muted mb-1">Reason: {{ $invoice->discount_reason }}</div>@endif
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Tax (GST)</span><span>{{ money($invoice->tax_amount) }}</span></div>
            @php $reduced = $invoice->items->sum(fn ($i) => to_paise($i->reduction)); @endphp
            @if ($reduced > 0)<div class="d-flex justify-content-between mb-1 small text-warning-emphasis" title="Charged below the standard price on some lines (already in the total)"><span><i class="bi bi-arrow-down-circle me-1"></i>Below standard prices</span><span>{{ money(from_paise($reduced)) }}</span></div>@endif
            <hr class="my-2">
            <div class="d-flex justify-content-between fs-5 fw-bold"><span>Grand total</span><span>{{ money($invoice->grand_total) }}</span></div>
            <div class="d-flex justify-content-between text-success"><span>Paid</span><span>{{ money($invoice->paid_amount) }}</span></div>
            <div class="d-flex justify-content-between fw-semibold {{ to_paise($invoice->balance_amount) > 0 ? 'text-danger' : '' }}"><span>Balance</span><span>{{ money($invoice->balance_amount) }}</span></div>
        </div></div>
        @if ($audit) @include('audit._trail', ['logs' => $audit]) @endif
    </div>
</div>

@can('collect', $invoice)
<div class="modal fade" id="collectModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" action="{{ route('invoices.payments.store', $invoice) }}" class="modal-content" novalidate data-loading-form data-confirm-fn="confirmCollect">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Collect due — {{ $invoice->invoice_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body vstack gap-3">
            <div class="alert alert-light border mb-0">Balance due: <strong>{{ money($invoice->balance_amount) }}</strong></div>
            <div>
                <label class="form-label" for="c_amount">Amount received (₹) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="number" step="0.01" min="0.01" max="{{ $invoice->balance_amount }}" name="amount" id="c_amount" value="{{ old('amount', $invoice->balance_amount) }}" required class="form-control @error('amount') is-invalid @enderror">
                    <button type="button" class="btn btn-outline-secondary" id="c_full">Full balance</button>
                    <div class="invalid-feedback">{{ $errors->first('amount') ?: 'Enter an amount up to the balance.' }}</div>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label" for="c_mode">Mode <span class="text-danger">*</span></label>
                    <select name="mode" id="c_mode" class="form-select @error('mode') is-invalid @enderror" required>
                        @foreach ($modes as $v => $l)<option value="{{ $v }}" @selected(old('mode', 'cash') === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" for="c_date">Date</label>
                    <input type="date" name="payment_date" id="c_date" value="{{ old('payment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="form-control @error('payment_date') is-invalid @enderror">
                </div>
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

@can('cancel', $invoice)
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="modal-content" novalidate data-loading-form data-confirm-fn="confirmCancel">
        @csrf
        <div class="modal-header"><h5 class="modal-title">Cancel {{ $invoice->invoice_number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            @if (to_paise($invoice->paid_amount) > 0)
                <div class="alert alert-warning">{{ money($invoice->paid_amount) }} has already been collected. It stays on record; refund it manually if required.</div>
            @endif
            <label class="form-label" for="reason">Reason for cancellation <span class="text-danger">*</span></label>
            <textarea name="reason" id="reason" rows="3" minlength="5" maxlength="255" required class="form-control @error('reason') is-invalid @enderror">{{ old('reason') }}</textarea>
            <div class="invalid-feedback">{{ $errors->first('reason') ?: 'A reason (at least 5 characters) is required.' }}</div>
            <div class="form-text">Cancelled invoices are kept for audit and cannot be reopened.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep invoice</button><button class="btn btn-danger">Cancel invoice</button></div>
    </form>
</div></div>
@endcan
@endsection

@push('scripts')
<script>
const inr = n => '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
window.confirmCollect = f => ({ title: 'Record this payment?', text: inr(f.amount.value) + ' by ' + f.mode.selectedOptions[0].text + ' for {{ $invoice->invoice_number }}.', confirmText: 'Yes, record payment', icon: 'question' });
window.confirmCancel = () => ({ title: 'Cancel this invoice?', text: 'This cannot be undone.', confirmText: 'Yes, cancel invoice' });

const refresh = () => $('#c_refWrap').toggleClass('d-none', $('#c_mode').val() === 'cash').find('input').prop('required', $('#c_mode').val() !== 'cash');
$('#c_mode').on('change', refresh); refresh();
$('#c_full').on('click', () => $('#c_amount').val(@json($invoice->balance_amount)).trigger('input'));
@if ($errors->any() && old('mode') !== null) new bootstrap.Modal('#collectModal').show(); @endif
@if ($errors->has('reason')) new bootstrap.Modal('#cancelModal').show(); @endif
</script>
@endpush
