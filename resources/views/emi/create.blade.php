@extends('layouts.app')
@section('title', 'New EMI plan')
@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('emi-plans.index') }}">EMI Plans</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
@if ($errors->any())
    <div class="alert alert-danger"><strong>Please fix the following:</strong>
        <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<form method="POST" action="{{ route('emi-plans.store') }}" id="emiForm" novalidate data-loading-form data-confirm-fn="confirmPlan">
    @csrf
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card mb-3"><div class="card-header">Patient &amp; treatment</div><div class="card-body row g-3">
                <div class="col-12">
                    <label for="patient_id" class="form-label">Patient <span class="text-danger">*</span></label>
                    @if ($invoice || $visit)
                        <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                        <input class="form-control" disabled value="{{ $patient->name }} · {{ $patient->phone }} · {{ $patient->uhid }}">
                        @if ($invoice)<input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                            <div class="form-text">Schedule for invoice <strong>{{ $invoice->invoice_number }}</strong> (balance {{ money($invoice->balance_amount) }}). Payments on the plan will also settle the invoice.</div>@endif
                        @if ($visit)<input type="hidden" name="visit_id" value="{{ $visit->id }}">@endif
                    @else
                        <select name="patient_id" id="patient_id" class="form-select js-patient-select @error('patient_id') is-invalid @enderror" required data-url="{{ route('patients.search') }}">
                            @php $sel = $patient ?? (old('patient_id') ? \App\Models\Patient::find(old('patient_id')) : null); @endphp
                            @if ($sel)<option value="{{ $sel->id }}" selected>{{ $sel->name }} · {{ $sel->phone }} · {{ $sel->uhid }}</option>@endif
                        </select>
                        <div class="invalid-feedback">{{ $errors->first('patient_id') ?: 'Please select a patient.' }}</div>
                    @endif
                </div>
                @unless ($invoice)
                <div class="col-12 d-none" id="billPickWrap">
                    <label class="form-label" for="invoice_id">Split an existing bill into instalments <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="invoice_id" id="invoice_id" class="form-select"><option value="">No: this is a separate plan</option></select>
                    <div class="form-text">The patient has unpaid bills. Pick one to pay it in instalments: payments on the plan then settle that bill too. Leave it as is for a treatment that has no bill yet.</div>
                </div>
                @endunless
                <div class="col-12">@include('partials.field', ['name' => 'treatment_name', 'label' => 'Treatment', 'value' => $treatment, 'required' => true, 'maxlength' => 200, 'placeholder' => 'e.g. Braces (orthodontics)'])</div>
                <div class="col-md-6">@include('partials.field', ['name' => 'total_amount', 'label' => 'Total amount (₹)', 'type' => 'number', 'value' => $total, 'required' => true, 'msg' => 'Enter the total amount.'])</div>
                <div class="col-md-6">@include('partials.field', ['name' => 'down_payment', 'label' => 'Down payment (₹)', 'type' => 'number', 'value' => old('down_payment', 0), 'help' => 'Collected now and receipted. Optional.'])</div>
                <div class="col-md-6 d-none" id="downModeWrap">
                    <label class="form-label" for="pay_mode">Down payment mode</label>
                    <select name="pay_mode" id="pay_mode" class="form-select @error('pay_mode') is-invalid @enderror">
                        @foreach ($modes as $m)<option value="{{ $m['value'] }}" @selected(old('pay_mode', 'cash') === $m['value'])>{{ $m['label'] }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6 d-none" id="downRefWrap">
                    <label class="form-label" for="pay_reference">Reference no. <span class="text-danger">*</span></label>
                    <input name="pay_reference" id="pay_reference" maxlength="100" value="{{ old('pay_reference') }}" class="form-control @error('pay_reference') is-invalid @enderror">
                    <div class="invalid-feedback">{{ $errors->first('pay_reference') ?: 'Required for non-cash payments.' }}</div>
                </div>
            </div></div>

            <div class="card"><div class="card-header">Instalments</div><div class="card-body row g-3">
                <div class="col-12">
                    <div class="btn-group w-100" role="group" aria-label="Calculate by">
                        <input type="radio" class="btn-check" name="calc_by" id="by_months" value="months" @checked(old('calc_by', 'monthly') === 'months')>
                        <label class="btn btn-outline-primary" for="by_months">I know the number of months</label>
                        <input type="radio" class="btn-check" name="calc_by" id="by_monthly" value="monthly" @checked(old('calc_by', 'monthly') === 'monthly')>
                        <label class="btn btn-outline-primary" for="by_monthly">I know the monthly amount</label>
                    </div>
                </div>
                <div class="col-md-6">@include('partials.field', ['name' => 'months', 'label' => 'Number of months', 'type' => 'number', 'value' => old('months'), 'msg' => 'Enter 1 to 60 months.'])</div>
                <div class="col-md-6">@include('partials.field', ['name' => 'monthly_amount', 'label' => 'Monthly amount (₹)', 'type' => 'number', 'value' => old('monthly_amount'), 'msg' => 'Enter the monthly amount.'])</div>
                <div class="col-md-6">@include('partials.field', ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'value' => old('start_date', today()->toDateString()), 'required' => true, 'help' => 'The first instalment falls on the next due day on/after this date.'])</div>
                <div class="col-md-6">@include('partials.field', ['name' => 'due_day', 'label' => 'Due day of month', 'type' => 'number', 'value' => old('due_day', today()->day > 28 ? 28 : today()->day), 'required' => true, 'msg' => 'Enter a day from 1 to 31.', 'help' => 'Day 29-31 falls on the last day of shorter months.'])</div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea name="notes" id="notes" rows="2" maxlength="1000" class="form-control">{{ old('notes') }}</textarea>
                </div>
            </div></div>
        </div>

        <div class="col-lg-6">
            <div class="card sticky-top" style="top:76px">
                <div class="card-header d-flex justify-content-between"><span>Schedule preview</span><span class="small text-muted" id="pvState"></span></div>
                <div class="card-body">
                    <div id="pvMsg" class="alert alert-light border mb-3">Fill in the amount and instalment details to see the schedule.</div>
                    <div id="pvSummary" class="row g-2 mb-3 d-none text-center">
                        <div class="col-4"><div class="stat-label">To finance</div><div class="fw-bold" id="sFin">—</div></div>
                        <div class="col-4"><div class="stat-label">Instalments</div><div class="fw-bold" id="sCount">—</div></div>
                        <div class="col-4"><div class="stat-label">Monthly</div><div class="fw-bold" id="sMonthly">—</div></div>
                        <div class="col-6"><div class="stat-label">First due</div><div class="fw-semibold" id="sFirst">—</div></div>
                        <div class="col-6"><div class="stat-label">Plan ends</div><div class="fw-semibold" id="sEnd">—</div></div>
                    </div>
                    <div class="table-responsive" style="max-height:340px">
                        <table class="table table-sm align-middle mb-0" id="pvTable"><thead class="table-light"><tr><th>#</th><th>Due date</th><th class="text-end">Amount</th></tr></thead><tbody></tbody></table>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <a href="{{ route('emi-plans.index') }}" class="btn btn-light">Cancel</a>
                    <button class="btn btn-primary px-4">Create plan</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
window.EMI_CFG = { previewUrl: @json(route('emi-plans.preview')), modes: @json($modes), isAdmin: @json($isAdmin),
    billsUrl: @json(route('patients.open-bills', ['patient' => '__ID__'])), oldBill: @json(old('invoice_id')) };
</script>
<script src="{{ asset('js/emi-form.js') }}"></script>
<script>
(function () {
    const $f = $('#emiForm');
    const paise = v => Math.round((parseFloat(v) || 0) * 100);
    const inr = p => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    $('#total_amount').attr({ min: 0.01, step: '0.01' });
    $('#down_payment').attr({ min: 0, step: '0.01' });
    $('#start_date').attr('min', EMI_CFG.isAdmin ? '' : new Date().toISOString().slice(0, 10));
    $('#due_day').attr({ min: 1, max: 31, step: 1 });

    function financed() { return paise($('#total_amount').val()) - paise($('#down_payment').val()); }

    function sync() {
        const down = paise($('#down_payment').val()), total = paise($('#total_amount').val());
        const msg = down >= total && total > 0 ? 'Down payment must be less than the total amount.' : '';
        $('#down_payment')[0].setCustomValidity(msg);
        $('#down_payment').toggleClass('is-invalid', !!msg).next('.invalid-feedback').text(msg);

        const mode = EMI_CFG.modes.find(m => m.value === $('#pay_mode').val());
        $('#downModeWrap').toggleClass('d-none', down <= 0);
        $('#downRefWrap').toggleClass('d-none', !(down > 0 && mode && mode.ref));
        $('#pay_reference').prop('required', down > 0 && !!(mode && mode.ref));
    }
    $('#total_amount, #down_payment, #pay_mode').on('input change', sync); sync();

    EmiPreview.bind($f, { amount: () => financed() / 100, onSummary: s => $('#sFin').text(inr(financed())) });

    // The patient's unpaid bills (if any) can be picked here instead of typing the amount again.
    const bills = {};
    function loadBills() {
        const pid = $('#patient_id').val() || $('[name=patient_id]').val();
        const $wrap = $('#billPickWrap'), $sel = $('#invoice_id');
        if (!$wrap.length) return;
        $sel.find('option:not(:first)').remove(); $wrap.addClass('d-none');
        if (!pid) return;
        $.getJSON(EMI_CFG.billsUrl.replace('__ID__', pid)).done(list => {
            list.forEach(b => { bills[b.id] = b; $sel.append(`<option value="${b.id}">${b.number} · ${b.date} · balance ${b.balance_fmt}</option>`); });
            if (list.length) $wrap.removeClass('d-none');
            if (EMI_CFG.oldBill) $sel.val(String(EMI_CFG.oldBill));
        });
    }
    $('#invoice_id').on('change', function () {
        const b = bills[this.value];
        if (!b) { $('#total_amount').removeAttr('max'); return; }
        $('#total_amount').val(b.balance.toFixed(2)).attr('max', b.balance).trigger('input');
        if (!$('#treatment_name').val()) $('#treatment_name').val('Bill ' + b.number).trigger('input');
    });
    $('#patient_id').on('select2:select select2:clear change', loadBills);
    loadBills();

    window.confirmPlan = () => {
        const down = paise($('#down_payment').val());
        return { title: 'Create this EMI plan?', icon: 'question', confirmText: 'Yes, create plan',
            text: 'Total ' + inr(paise($('#total_amount').val())) + (down > 0 ? ' — a down payment of ' + inr(down) + ' will be recorded now.' : '.') };
    };
})();
</script>
@endpush
