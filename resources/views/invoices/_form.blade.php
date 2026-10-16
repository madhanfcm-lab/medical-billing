{{--
  The bill form. Used on the full "New bill" page and inside the slide-over on the patient page.
  Left column: who / when / what was done.  Right column (sticky): discount, total, payment, so the cashier
  always sees the amount and how they are paying while adding services.
  Expects: $patient (nullable), $visit (nullable), $doctorId, $doctors, $modes, $embedded (bool),
           $hasVisitToday (bool), $previousDue (string, "0.00" when none)
  JavaScript: public/js/invoice-form.js, configured through window.INVOICE_CFG (see _form-scripts).
--}}
@php
    $embedded = $embedded ?? false;
    $previousDue = $previousDue ?? '0.00';
    $hasVisitToday = $hasVisitToday ?? false;
    $recordVisit = old('create_visit', $visit || $hasVisitToday ? false : true);
    $billErrors = $errors->any() && (old('items') !== null || old('patient_id') !== null) && ((bool) old('return_to') === $embedded);
@endphp
<form method="POST" action="{{ route('invoices.store') }}" id="invoiceForm" class="bill-sheet @if ($embedded) bill-embedded @endif" novalidate data-loading-form data-confirm-fn="confirmInvoice">
    @csrf
    @if ($embedded)<input type="hidden" name="return_to" value="patient">@endif

    @if ($billErrors)
        <div class="alert alert-danger"><strong>Please fix the following:</strong>
            <ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    @if ($embedded && (float) $previousDue > 0)
        <div class="prev-dues">
            <span><i class="bi bi-exclamation-circle me-1"></i>{{ $patient->name }} still owes <strong>{{ money($previousDue) }}</strong> from earlier bills.</span>
            @can('payments.collect')<button type="button" class="btn btn-sm btn-outline-warning" data-open-receive>Receive payment first</button>@endcan
        </div>
    @endif

    <div class="row g-3 bill-layout">
        {{-- ───── left: who, when, what ───── --}}
        <div class="col-xl-8">
            <div class="section">
                <div class="section-title">Who and when</div>
                <div class="row g-3">
                    <div class="col-12 @if ($embedded) d-none @endif">
                        <label for="patient_id" class="form-label">Patient <span class="text-danger">*</span></label>
                        @if ($visit || $embedded)
                            <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                            @if ($visit)<input type="hidden" name="visit_id" value="{{ $visit->id }}">@endif
                            <input class="form-control" disabled value="{{ $patient->name }} · {{ $patient->phone }} · {{ $patient->uhid }}">
                            @if ($visit)<div class="form-text">Bill for the visit on {{ format_date($visit->visit_date) }}.</div>@endif
                        @else
                            <select name="patient_id" id="patient_id" class="form-select js-patient-select @error('patient_id') is-invalid @enderror" required
                                    data-url="{{ route('patients.search') }}" data-register-url="{{ auth()->user()->can('patients.manage') ? route('patients.create') : '' }}">
                                @php $sel = $patient ?? (old('patient_id') ? \App\Models\Patient::find(old('patient_id')) : null); @endphp
                                @if ($sel)<option value="{{ $sel->id }}" selected>{{ $sel->name }} · {{ $sel->phone }} · {{ $sel->uhid }}</option>@endif
                            </select>
                            <div class="invalid-feedback">{{ $errors->first('patient_id') ?: 'Please select a patient.' }}</div>
                        @endif
                    </div>
                    <div class="col-md-7">
                        <label for="doctor_id" class="form-label">Doctor <span class="text-danger d-none" id="doctorReq">*</span></label>
                        <select name="doctor_id" id="doctor_id" class="form-select @error('doctor_id') is-invalid @enderror">
                            <option value="">Select doctor…</option>
                            @foreach ($doctors as $d)<option value="{{ $d->id }}" @selected((string) old('doctor_id', $doctorId) === (string) $d->id)>{{ $d->name }}@if ($d->specialization) · {{ $d->specialization }}@endif</option>@endforeach
                        </select>
                        <div class="invalid-feedback">{{ $errors->first('doctor_id') ?: 'Choose the doctor to record this visit.' }}</div>
                    </div>
                    <div class="col-md-5">
                        @include('partials.field', ['name' => 'invoice_date', 'label' => 'Bill date', 'type' => 'date', 'value' => old('invoice_date', now()->toDateString()), 'required' => true])
                    </div>
                    @unless ($visit)
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="create_visit" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="create_visit" id="create_visit" value="1" @checked($recordVisit)>
                            <label class="form-check-label fw-semibold" for="create_visit">Also record today's visit</label>
                            <div class="form-text mb-0">
                                @if ($hasVisitToday)A visit is already recorded for today. Turn this on only if this is a separate visit.
                                @else Saves the doctor, date and treatment as a visit on the patient's history.@endif
                            </div>
                        </div>
                        <div id="visitComplaintWrap" class="mt-2 @if (! $recordVisit) d-none @endif">
                            <input name="visit_complaint" id="visit_complaint" maxlength="2000" value="{{ old('visit_complaint') }}" class="form-control" placeholder="Reason for the visit (optional), e.g. toothache, check-up">
                        </div>
                    </div>
                    @endunless
                </div>
            </div>

            <div class="section">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="section-title mb-0">Services and treatments</div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addRow" title="Alt + A"><i class="bi bi-plus-lg me-1"></i>Add row</button>
                </div>
                <div class="table-responsive">
                    <table class="table bill-items mb-0" id="itemsTable">
                        <thead><tr>
                            <th>Service</th><th style="width:66px">Qty</th><th style="width:108px">Price (₹)</th>
                            <th style="width:92px">Discount</th><th style="width:68px">GST %</th><th class="text-end" style="width:102px">Amount</th><th style="width:38px"></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="small text-muted mt-2"><kbd>Enter</kbd> moves across a row and adds the next one at the end.</div>
            </div>

            <div class="section mb-0 mb-xl-3">
                <div class="section-title">Note on the bill</div>
                <textarea name="notes" id="notes" rows="2" maxlength="1000" class="form-control" placeholder="Optional. Printed on the bill.">{{ old('notes') }}</textarea>
            </div>
        </div>

        {{-- ───── right: discount, total, payment (stays in view) ───── --}}
        <div class="col-xl-4">
            <div class="bill-side">
                <div class="section">
                    <div class="section-title">Discount</div>
                    <div class="d-flex gap-2 align-items-center">
                        <select name="discount_type" id="discount_type" class="d-none">
                            <option value="amount" @selected(old('discount_type', 'amount') === 'amount')>Amount (₹)</option>
                            <option value="percent" @selected(old('discount_type') === 'percent')>Percent (%)</option>
                        </select>
                        <div class="seg" id="discountSeg">
                            <input type="radio" class="btn-check" id="dt_amount" value="amount" name="dt_ui" @checked(old('discount_type', 'amount') === 'amount')><label for="dt_amount">₹</label>
                            <input type="radio" class="btn-check" id="dt_percent" value="percent" name="dt_ui" @checked(old('discount_type') === 'percent')><label for="dt_percent">%</label>
                        </div>
                        <div class="flex-fill">
                            <input type="number" step="0.01" min="0" name="discount_value" id="discount_value" value="{{ old('discount_value', 0) }}" class="form-control @error('discount_value') is-invalid @enderror" aria-label="Discount">
                            <div class="invalid-feedback">{{ $errors->first('discount_value') }}</div>
                        </div>
                    </div>
                    <div id="reasonWrap" class="mt-2">
                        <input name="discount_reason" id="discount_reason" maxlength="255" value="{{ old('discount_reason') }}" class="form-control @error('discount_reason') is-invalid @enderror" placeholder="Reason for the discount *" aria-label="Reason for discount">
                        <div class="invalid-feedback">{{ $errors->first('discount_reason') ?: 'A reason is required when a discount is given.' }}</div>
                    </div>
                </div>

                <div class="section mb-0">
                    <div class="section-title">Total and payment</div>
                    <div class="totals-box mb-3">
                        <div class="kv"><span>Subtotal</span><strong id="tSubtotal">₹0.00</strong></div>
                        <div class="kv"><span>Discount</span><strong id="tDiscount">− ₹0.00</strong></div>
                        <div class="kv"><span>GST</span><strong id="tTax">₹0.00</strong></div>
                        <div class="kv d-none" id="reductionRow" title="Some prices on this bill are lower than the standard price. The total above already uses the lower prices.">
                            <span class="small text-warning-emphasis"><i class="bi bi-arrow-down-circle me-1"></i>Below standard prices</span><strong class="small text-warning-emphasis" id="tReduction">₹0.00</strong>
                        </div>
                        <div class="grand"><span>Total</span><span id="tGrand">₹0.00</span></div>
                    </div>

                    <label class="form-label" for="pay_amount">Paying now (₹)</label>
                    <div class="d-flex gap-2 mb-2">
                        <input type="number" step="0.01" min="0" name="pay_amount" id="pay_amount" value="{{ old('pay_amount', 0) }}" class="form-control form-control-lg @error('pay_amount') is-invalid @enderror">
                        <button type="button" class="btn btn-outline-success text-nowrap" id="payFull">Pay full</button>
                        <button type="button" class="btn btn-outline-secondary text-nowrap" id="payLater">Pay later</button>
                    </div>
                    <div class="invalid-feedback d-block mb-2">{{ $errors->first('pay_amount') }}</div>

                    <div id="modeWrap">
                        <select name="pay_mode" id="pay_mode" class="d-none">
                            @foreach ($modes as $m)<option value="{{ $m['value'] }}" @selected(old('pay_mode', 'cash') === $m['value'])>{{ $m['label'] }}</option>@endforeach
                        </select>
                        <div class="seg mb-2" id="modeSeg">
                            @foreach ($modes as $m)
                                <input type="radio" class="btn-check" name="mode_ui" id="m_{{ $m['value'] }}" value="{{ $m['value'] }}" @checked(old('pay_mode', 'cash') === $m['value'])>
                                <label for="m_{{ $m['value'] }}">{{ $m['label'] }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div id="refWrap" class="d-none mb-2">
                        <input name="pay_reference" id="pay_reference" maxlength="100" value="{{ old('pay_reference') }}" class="form-control @error('pay_reference') is-invalid @enderror" placeholder="Reference no. (UPI ref / card slip / cheque no.) *">
                        <div class="invalid-feedback">{{ $errors->first('pay_reference') ?: 'Required for non-cash payments.' }}</div>
                    </div>
                    <div class="kv mt-2"><span class="fw-semibold">Balance after this payment</span><strong id="tBalance" class="text-danger">₹0.00</strong></div>
                </div>
            </div>
        </div>
    </div>

    <div class="sticky-foot">
        <span class="text-muted small d-none d-md-inline">Check the total, choose how they're paying, then save.</span>
        <div class="d-flex gap-2 ms-auto">
            @if ($embedded)<button type="button" class="btn btn-light" data-bs-dismiss="offcanvas">Cancel</button>
            @else<a href="{{ route('invoices.index') }}" class="btn btn-light">Cancel</a>@endif
            <button class="btn btn-primary btn-lg px-4"><i class="bi bi-check2-circle me-1"></i>Save bill</button>
        </div>
    </div>
</form>

<template id="rowTpl">
    <tr class="item-row">
        <td><select class="form-select form-select-sm js-service" data-name="service_id" required></select></td>
        <td><input type="number" class="form-control form-control-sm text-end js-qty" data-name="quantity" min="1" max="9999" step="1" value="1" required></td>
        <td><input type="number" class="form-control form-control-sm text-end js-price" data-name="unit_price" min="0.01" step="0.01" required><div class="std-hint"></div></td>
        <td><input type="number" class="form-control form-control-sm text-end js-disc" data-name="discount_amount" min="0" step="0.01" value="0"></td>
        <td><input type="number" class="form-control form-control-sm text-end js-gst" data-name="gst_percent" min="0" max="100" step="0.01" value="0"></td>
        <td class="text-end fw-semibold js-line">₹0.00</td>
        <td><button type="button" class="btn btn-sm btn-icon btn-outline-danger js-del" title="Remove row" tabindex="-1"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>

{{-- Add a service to the catalogue without leaving the bill (opened from the service dropdown). --}}
<div class="modal fade" id="quickServiceModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form id="quickServiceForm" class="modal-content" novalidate>
        <div class="modal-header"><div><div class="small text-muted">Not in the list yet?</div><h5 class="modal-title">Add a new service</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12"><label class="form-label" for="qs_name">Service / treatment name <span class="text-danger">*</span></label>
                <input name="name" id="qs_name" maxlength="150" class="form-control" required autocomplete="off"><div class="invalid-feedback"></div></div>
            <div class="col-6"><label class="form-label" for="qs_category">Category <span class="text-danger">*</span></label>
                <select name="category" id="qs_category" class="form-select" required>
                    @foreach (\App\Enums\ServiceCategory::cases() as $c)<option value="{{ $c->value }}" @selected($c === \App\Enums\ServiceCategory::Procedure)>{{ $c->label() }}</option>@endforeach
                </select><div class="invalid-feedback"></div></div>
            <div class="col-6"><label class="form-label" for="qs_price">Usual price (₹) <span class="text-danger">*</span></label>
                <input type="number" name="default_price" id="qs_price" min="0" step="0.01" class="form-control" required><div class="invalid-feedback"></div></div>
            <div class="col-6"><label class="form-label" for="qs_gst">GST % <span class="text-muted fw-normal">(optional)</span></label>
                <input type="number" name="gst_percent" id="qs_gst" min="0" max="100" step="0.01" value="0" class="form-control"><div class="invalid-feedback"></div></div>
            <div class="col-12 form-text mt-0">It is saved to your services list and selected on this bill. The price can still be changed on every bill.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add and use</button></div>
    </form>
</div></div>
