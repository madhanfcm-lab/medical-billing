{{--
  "Receive payment": ONE dialog for everything this patient owes.
    · Bills tab    : one amount, applied to the oldest unpaid bill first (one combined receipt).
    · EMI plan tabs: one per open plan, applied to the oldest unpaid instalment first.
  Needs: $patient, $dues, $canReceive, $canReceiveBills, $canReceiveEmi (set by patients/show).
--}}
@if ($canReceive)
@php
    $open = $dues['invoices']->reject->on_emi->values();
    $plans = $canReceiveEmi ? $dues['open_plans'] : collect();
    $planErr = old('_plan');
    $active = request('receive_plan') ? 'plan-'.request('receive_plan')
        : ($planErr ? 'plan-'.$planErr : ($canReceiveBills ? 'bills' : 'plan-'.$plans->first()?->id));
    $tabCount = ($canReceiveBills ? 1 : 0) + $plans->count();
    $modes = \App\Enums\PaymentMode::cases();
@endphp
<div class="modal fade" id="receiveModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
        <div><div class="small text-muted">Receive payment from</div><h5 class="modal-title">{{ $patient->name }}</h5></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>

    @if ($tabCount > 1)
        <div class="px-4 pt-3">
            <ul class="nav nav-pills gap-1" role="tablist">
                @if ($canReceiveBills)
                    <li class="nav-item"><button class="nav-link {{ $active === 'bills' ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#rp-bills" type="button">Bills <span class="opacity-75">· {{ money($dues['collectable']) }}</span></button></li>
                @endif
                @foreach ($plans as $pl)
                    <li class="nav-item"><button class="nav-link {{ $active === 'plan-'.$pl->id ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#rp-plan-{{ $pl->id }}" type="button">EMI · {{ $pl->treatment_name }} <span class="opacity-75">· {{ money($pl->balance_amount) }}</span></button></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tab-content">
        {{-- ───── bills ───── --}}
        @if ($canReceiveBills)
        <div class="tab-pane fade {{ $active === 'bills' ? 'show active' : '' }}" id="rp-bills">
            <form method="POST" action="{{ route('patients.payments.store', $patient) }}" novalidate data-loading-form data-confirm-fn="confirmReceive">
                @csrf
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="text-uppercase small fw-semibold text-muted mb-2" style="letter-spacing:.06em">Unpaid bills, oldest first</div>
                            <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="dueTable">
                                <thead><tr><th>Bill</th><th class="text-end">Balance</th><th class="text-end">This payment</th></tr></thead>
                                <tbody>@foreach ($open as $i)
                                    <tr class="js-due-row" data-balance="{{ round((float) $i->balance_amount * 100) }}">
                                        <td>{{ $i->invoice_number }}<div class="small text-muted">{{ format_date($i->invoice_date) }}</div></td>
                                        <td class="text-end">{{ money($i->balance_amount) }}</td>
                                        <td class="text-end fw-semibold js-alloc text-muted">—</td>
                                    </tr>
                                @endforeach</tbody>
                                <tfoot><tr><th>Total due</th><th class="text-end">{{ money($dues['collectable']) }}</th><th></th></tr></tfoot>
                            </table></div>
                            <div class="form-text mt-2">The amount you receive is applied to the oldest bill first, then the next. One receipt is printed for the whole amount.</div>
                        </div>
                        <div class="col-md-6 vstack gap-3">
                            <div>
                                <label class="form-label" for="r_amount">Amount received (₹) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" max="{{ $dues['collectable'] }}" name="amount" id="r_amount" value="{{ ! $planErr ? old('amount', $dues['collectable']) : $dues['collectable'] }}" required class="form-control form-control-lg @if (! $planErr) @error('amount') is-invalid @enderror @endif">
                                <div class="invalid-feedback">{{ (! $planErr ? $errors->first('amount') : '') ?: 'Enter an amount up to the total due.' }}</div>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-success" id="r_all">Everything ({{ money($dues['collectable']) }})</button>
                                    @if ($open->count() > 1)<button type="button" class="btn btn-sm btn-outline-secondary" id="r_first">Oldest bill only</button>@endif
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Paid by</label>
                                <select name="mode" id="r_mode" class="d-none">@foreach (\App\Enums\PaymentMode::options() as $v => $l)<option value="{{ $v }}" @selected(old('mode', 'cash') === $v)>{{ $l }}</option>@endforeach</select>
                                <div class="seg" id="r_seg">
                                    @foreach ($modes as $m)
                                        <input type="radio" class="btn-check" name="r_mode_ui" id="r_m_{{ $m->value }}" value="{{ $m->value }}" @checked(old('mode', 'cash') === $m->value)>
                                        <label for="r_m_{{ $m->value }}">{{ $m->label() }}</label>
                                    @endforeach
                                </div>
                            </div>
                            <div id="r_refWrap" class="d-none">
                                <input name="reference_no" id="r_ref" maxlength="100" value="{{ old('reference_no') }}" class="form-control @if (! $planErr) @error('reference_no') is-invalid @enderror @endif" placeholder="Reference no. (UPI ref / card slip / cheque no.) *">
                                <div class="invalid-feedback">{{ (! $planErr ? $errors->first('reference_no') : '') ?: 'Reference number is required for non-cash payments.' }}</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-5"><label class="form-label" for="r_date">Date</label>
                                    <input type="date" name="payment_date" id="r_date" value="{{ old('payment_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="form-control"></div>
                                <div class="col-7"><label class="form-label" for="r_notes">Note</label><input name="notes" id="r_notes" value="{{ old('notes') }}" maxlength="255" class="form-control" placeholder="Optional"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-success btn-lg px-4"><i class="bi bi-check2-circle me-1"></i>Receive payment</button></div>
            </form>
        </div>
        @endif

        {{-- ───── one tab per open EMI plan ───── --}}
        @foreach ($plans as $pl)
        @php
            $isErr = (string) $planErr === (string) $pl->id;
            $unpaid = $pl->installments->map(fn ($i) => ['no' => $i->installment_no, 'balance' => (float) $i->balance])->values();
            $next = $pl->installments->first();
        @endphp
        <div class="tab-pane fade {{ $active === 'plan-'.$pl->id ? 'show active' : '' }}" id="rp-plan-{{ $pl->id }}">
            <form method="POST" action="{{ route('emi-plans.collect', $pl) }}" class="js-emi-pane" novalidate data-loading-form data-confirm-fn="confirmReceiveEmi"
                  data-open='@json($unpaid)' data-balance="{{ $pl->balance_amount }}">
                @csrf
                <input type="hidden" name="return_to" value="patient"><input type="hidden" name="_plan" value="{{ $pl->id }}">
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="p-3 rounded-3 bg-light border">
                                <div class="fw-semibold">{{ $pl->plan_number }} · {{ $pl->treatment_name }}</div>
                                @if ($pl->invoice)<div class="small text-muted">Pays bill {{ $pl->invoice->invoice_number }}</div>@endif
                                <div class="kv mt-2"><span>Balance left</span><strong class="text-danger">{{ money($pl->balance_amount) }}</strong></div>
                                @if ($next)<div class="kv"><span>Next instalment</span><strong>#{{ $next->installment_no }} · {{ money($next->balance) }}</strong></div>
                                    <div class="kv"><span>Due on</span><strong class="{{ $next->due_date->lt(today()) ? 'text-danger' : '' }}">{{ format_date($next->due_date) }}{{ $next->due_date->lt(today()) ? ' (overdue)' : '' }}</strong></div>@endif
                            </div>
                            <div class="form-text mt-2">Any amount is fine: it goes to the oldest unpaid instalment first and carries over to the next.</div>
                        </div>
                        <div class="col-md-7 vstack gap-3">
                            <div>
                                <label class="form-label">Amount received (₹) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" max="{{ $pl->balance_amount }}" name="amount" value="{{ $isErr ? old('amount') : ($next?->balance) }}" required class="form-control form-control-lg js-amt @if ($isErr) @error('amount') is-invalid @enderror @endif">
                                <div class="invalid-feedback">{{ ($isErr ? $errors->first('amount') : '') ?: 'Enter an amount up to the plan balance.' }}</div>
                                <div class="d-flex gap-2 mt-2">
                                    @if ($next)<button type="button" class="btn btn-sm btn-outline-secondary js-next">Next instalment</button>@endif
                                    <button type="button" class="btn btn-sm btn-outline-success js-settle">Settle in full ({{ money($pl->balance_amount) }})</button>
                                </div>
                                <div class="small mt-2 js-preview"></div>
                            </div>
                            <div>
                                <label class="form-label">Paid by</label>
                                <select name="mode" class="d-none js-mode">@foreach (\App\Enums\PaymentMode::options() as $v => $l)<option value="{{ $v }}" @selected(($isErr ? old('mode', 'cash') : 'cash') === $v)>{{ $l }}</option>@endforeach</select>
                                <div class="seg js-seg">
                                    @foreach ($modes as $m)
                                        <input type="radio" class="btn-check" name="e{{ $pl->id }}_ui" id="e{{ $pl->id }}_{{ $m->value }}" value="{{ $m->value }}" @checked(($isErr ? old('mode', 'cash') : 'cash') === $m->value)>
                                        <label for="e{{ $pl->id }}_{{ $m->value }}">{{ $m->label() }}</label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="d-none js-ref-wrap">
                                <input name="reference_no" maxlength="100" value="{{ $isErr ? old('reference_no') : '' }}" class="form-control js-ref @if ($isErr) @error('reference_no') is-invalid @enderror @endif" placeholder="Reference no. (UPI ref / card slip / cheque no.) *">
                                <div class="invalid-feedback">{{ ($isErr ? $errors->first('reference_no') : '') ?: 'Reference number is required for non-cash payments.' }}</div>
                            </div>
                            <div class="row g-2">
                                <div class="col-5"><label class="form-label">Date</label><input type="date" name="payment_date" value="{{ $isErr ? old('payment_date', now()->toDateString()) : now()->toDateString() }}" max="{{ now()->toDateString() }}" required class="form-control"></div>
                                <div class="col-7"><label class="form-label">Note</label><input name="notes" maxlength="255" value="{{ $isErr ? old('notes') : '' }}" class="form-control" placeholder="Optional"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-success btn-lg px-4"><i class="bi bi-check2-circle me-1"></i>Record payment</button></div>
            </form>
        </div>
        @endforeach
    </div>
</div></div></div>

@push('scripts')
<script>
(function () {
    const inr = p => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const paise = v => Math.round((parseFloat(v) || 0) * 100);

    // ----- bills tab -----
    const rows = [...document.querySelectorAll('.js-due-row')];
    if (rows.length) {
        const total = () => rows.reduce((a, r) => a + Number(r.dataset.balance), 0);
        const allocate = () => {
            let left = paise($('#r_amount').val());
            rows.forEach(r => {
                const take = Math.min(left, Number(r.dataset.balance));
                const cell = r.querySelector('.js-alloc');
                cell.textContent = take > 0 ? inr(take) + (take < Number(r.dataset.balance) ? ' (part)' : ' ✓') : '—';
                cell.classList.toggle('text-muted', take === 0); cell.classList.toggle('text-success', take > 0);
                left -= take;
            });
            const bad = paise($('#r_amount').val()) > total();
            $('#r_amount')[0].setCustomValidity(bad ? 'Amount cannot exceed the total due.' : '');
            $('#r_amount').toggleClass('is-invalid', bad).next('.invalid-feedback').text(bad ? 'Amount cannot exceed the total due.' : '');
        };
        const refSync = () => { const need = $('#r_mode').val() !== 'cash'; $('#r_refWrap').toggleClass('d-none', !need); $('#r_ref').prop('required', need); };
        $('#r_amount').on('input', allocate);
        $('#r_all').on('click', () => { $('#r_amount').val((total() / 100).toFixed(2)); allocate(); });
        $('#r_first').on('click', () => { $('#r_amount').val((Number(rows[0].dataset.balance) / 100).toFixed(2)); allocate(); });
        $('#r_seg').on('change', 'input', function () { $('#r_mode').val(this.value); refSync(); });
        refSync(); allocate();

        window.confirmReceive = f => {
            const amt = paise(f.amount.value);
            let left = amt, n = 0; rows.forEach(r => { const t = Math.min(left, Number(r.dataset.balance)); if (t > 0) n++; left -= t; });
            return { title: 'Receive this payment?', icon: 'question', confirmText: 'Yes, receive ' + inr(amt),
                text: inr(amt) + ' by ' + f.mode.selectedOptions[0].text + ' will be applied to ' + n + (n === 1 ? ' bill' : ' bills') + ', oldest first.' };
        };
    }

    // ----- one EMI tab per open plan -----
    $('.js-emi-pane').each(function () {
        const $p = $(this), open = $p.data('open') || [], balance = paise($p.data('balance')), $amt = $p.find('.js-amt');
        const preview = () => {
            let left = paise($amt.val()); const parts = [];
            open.forEach(i => { if (left <= 0) return; const b = paise(i.balance), t = Math.min(left, b); parts.push('#' + i.no + ' ← ' + inr(t) + (t < b ? ' (part)' : '')); left -= t; });
            $p.find('.js-preview').html(parts.length ? '<span class="text-muted">This will cover:</span> ' + parts.join(' · ') : '');
            const bad = paise($amt.val()) > balance;
            $amt[0].setCustomValidity(bad ? 'Amount cannot exceed the plan balance.' : '');
            $amt.toggleClass('is-invalid', bad).next('.invalid-feedback').text(bad ? 'Amount cannot exceed the plan balance.' : '');
        };
        const refSync = () => { const need = $p.find('.js-mode').val() !== 'cash'; $p.find('.js-ref-wrap').toggleClass('d-none', !need); $p.find('.js-ref').prop('required', need); };
        $amt.on('input', preview);
        $p.find('.js-next').on('click', () => { $amt.val(open.length ? open[0].balance.toFixed(2) : ''); preview(); });
        $p.find('.js-settle').on('click', () => { $amt.val((balance / 100).toFixed(2)); preview(); });
        $p.find('.js-seg').on('change', 'input', function () { $p.find('.js-mode').val(this.value); refSync(); });
        refSync(); preview();
    });
    window.confirmReceiveEmi = f => {
        const amt = paise(f.amount.value), bal = paise($(f).data('balance')), settle = amt === bal;
        return { title: settle ? 'Settle the plan in full?' : 'Record this instalment payment?', icon: 'question', confirmText: 'Yes, record payment',
            text: inr(amt) + ' by ' + f.mode.selectedOptions[0].text + (settle ? ' will complete the plan.' : ', applied to the oldest unpaid instalment first.') };
    };

    // "Collect instalment" in the Dues panel opens this dialog on that plan's tab
    $(document).on('click', '[data-receive-plan]', function (e) {
        e.preventDefault();
        const tab = document.querySelector('[data-bs-target="#rp-plan-' + $(this).data('receive-plan') + '"]');
        if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
        bootstrap.Modal.getOrCreateInstance('#receiveModal').show();
    });
})();
</script>
@endpush
@endif
