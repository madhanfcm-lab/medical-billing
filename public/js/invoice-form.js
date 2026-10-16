/*
 * Bill entry screen.
 *  - The calculation mirrors App\Services\InvoiceCalculator exactly (integer paise, same rounding),
 *    so what the cashier sees is what gets saved.
 *  - Choosing a doctor adds that doctor's consultation fee as the first line (removable, price editable).
 *  - Every line remembers its STANDARD price; a price changed on the bill is flagged and totalled as a "price reduction".
 *  - A service that is not in the list can be added from the dropdown without leaving the bill.
 */
(function () {
    const cfg = window.INVOICE_CFG;
    const services = cfg.services;               // mutable: quick-added services are pushed here
    const doctors = cfg.doctors || {};           // { id: { name, fee } }
    const $form = $('#invoiceForm');
    const $body = $('#itemsTable tbody');
    let rowSeq = 0;
    let dismissedDoctor = null;                  // the doctor whose fee line the user removed (do not re-add it)
    let quickTarget = null;                      // the service dropdown waiting for a newly added service

    const paise = v => Math.round((parseFloat(v) || 0) * 100);
    const inr = p => '₹' + (p / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = t => $('<i>').text(t == null ? '' : t).html();
    const modeOf = v => cfg.modes.find(m => m.value === v);
    const serviceById = id => services.find(s => String(s.id) === String(id));
    const searchTerm = () => ($('.select2-container--open .select2-search__field').val() || '').trim();

    // ---------- service dropdown ----------
    function optionHtml(s) { return `<option value="${s.id}" data-price="${s.price}" data-gst="${s.gst}">${esc(s.name)} — ₹${s.price}</option>`; }
    function serviceOptions() {
        const groups = {};
        services.forEach(s => (groups[s.group] = groups[s.group] || []).push(s));
        let html = '<option value="">Select service…</option>';
        if (cfg.quickUrl) html += '<option value="__new__">＋ Add a new service…</option>';
        Object.keys(groups).forEach(g => { html += `<optgroup label="${esc(g)}">` + groups[g].map(optionHtml).join('') + '</optgroup>'; });
        return html;
    }
    function addServiceOption(s) {
        services.push(s);
        $('.js-service').each(function () {
            let $g = $(this).children('optgroup').filter((i, e) => e.label === s.group);
            if (!$g.length) $g = $(`<optgroup label="${esc(s.group)}"></optgroup>`).appendTo(this);
            $g.append(optionHtml(s));
        });
    }
    const noResults = () => cfg.quickUrl
        ? $(`<div class="pt-empty"><div class="text-muted mb-1">No service matches “${esc(searchTerm())}”.</div><a href="#" class="js-quick-add"><i class="bi bi-plus-circle me-1"></i>Add “${esc(searchTerm())}” as a new service</a></div>`)
        : 'No service found';

    // ---------- rows ----------
    function addRow(data = {}, auto = false) {
        const i = rowSeq++;
        const $row = $($('#rowTpl').html());

        if (auto) {
            $row.attr('data-auto', 'doctor').data('auto', 'doctor');
            $row.children().first().html(`<div class="fw-semibold js-auto-label"></div>
                <div class="small text-muted"><i class="bi bi-person-badge me-1"></i>Doctor's fee · added automatically</div>
                <input type="hidden" data-name="service_id" value="${cfg.consultId}"><input type="hidden" data-name="description" class="js-auto-desc"><input type="hidden" data-name="doctor_fee" value="1">`);
        }
        $row.find('[data-name]').each(function () { $(this).attr('name', `items[${i}][${$(this).data('name')}]`); });

        if (auto) {
            $body.prepend($row);
        } else {
            const $sel = $row.find('.js-service').html(serviceOptions());
            $body.append($row);
            const $drawer = $form.closest('.offcanvas');
            $sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: 'Select service…', dropdownAutoWidth: true,
                dropdownParent: $drawer.length ? $drawer : $(document.body), language: { noResults } });
            if (data.service_id) {
                $sel.val(String(data.service_id)).trigger('change.select2');
                const s = serviceById(data.service_id); if (s) $row.data('list', s.price);
            }
        }
        if (data.quantity) $row.find('.js-qty').val(data.quantity);
        if (data.unit_price) $row.find('.js-price').val(data.unit_price);
        if (data.discount_amount) $row.find('.js-disc').val(data.discount_amount);
        if (data.gst_percent) $row.find('.js-gst').val(data.gst_percent);
        return $row;
    }

    function focusService($row) { const $s = $row.find('.js-service'); if ($s.length) $s.select2('open'); else $row.find('.js-qty').trigger('focus'); }

    // ---------- the doctor's fee line ----------
    function syncDoctorFee() {
        const id = $('#doctor_id').val();
        const d = doctors[id];
        const $auto = $body.find('.item-row[data-auto=doctor]');

        if (id !== dismissedDoctor) dismissedDoctor = null;       // a different doctor: start fresh
        if (!d || !(d.fee > 0)) { $auto.remove(); recalc(); return; }

        const label = 'Consultation · ' + d.name;
        let $row = $auto;
        if (!$row.length) {
            if (dismissedDoctor === id) return;
            $row = addRow({ unit_price: d.fee }, true);
        } else if (!$row.data('touched')) {
            $row.find('.js-price').val(d.fee);
        }
        $row.data('list', d.fee);
        $row.find('.js-auto-label').text(label);
        $row.find('.js-auto-desc').val(label);
        recalc();
    }

    // ---------- maths ----------
    function recalc() {
        let subtotal = 0, rawTax = 0, reduced = 0;

        $body.find('.item-row').each(function () {
            const $r = $(this);
            const qty = parseInt($r.find('.js-qty').val(), 10) || 0;
            const price = paise($r.find('.js-price').val());
            const gross = qty * price;
            const disc = paise($r.find('.js-disc').val());
            const gst = parseFloat($r.find('.js-gst').val()) || 0;

            const bad = disc > gross;
            $r.find('.js-disc')[0].setCustomValidity(bad ? 'Item discount cannot exceed the item amount.' : '');
            $r.find('.js-disc').toggleClass('is-invalid', bad).attr('title', bad ? 'Item discount cannot exceed the item amount.' : '');

            // standard price vs what is being charged
            const list = $r.data('list');
            const $hint = $r.find('.std-hint');
            if (list !== undefined && list !== null && price > 0) {
                const L = paise(list);
                if (price < L) { $hint.html(`<span class="hint-down">Std ${inr(L)} · ↓ ${inr(L - price)} less</span>`); reduced += (L - price) * qty; }
                else if (price > L) $hint.html(`<span class="hint-up">Std ${inr(L)} · ↑ ${inr(price - L)} more</span>`);
                else $hint.html(`<span class="hint-same">Std ${inr(L)}</span>`);
            } else { $hint.empty(); }

            const taxable = Math.max(gross - disc, 0);
            const tax = Math.round(taxable * gst / 100);
            $r.find('.js-line').text(inr(taxable + tax));
            subtotal += taxable;
            rawTax += tax;
        });

        // Bill-level discount
        const type = $('#discount_type').val();
        const val = parseFloat($('#discount_value').val()) || 0;
        let discount = type === 'percent' ? Math.round(subtotal * val / 100) : paise(val);
        let discMsg = '';
        if (type === 'percent' && val > 100) discMsg = 'Discount cannot exceed 100%.';
        else if (discount > subtotal) discMsg = 'Discount cannot exceed the subtotal.';
        $('#discount_value')[0].setCustomValidity(discMsg);
        $('#discount_value').toggleClass('is-invalid', !!discMsg).next('.invalid-feedback').text(discMsg || '');
        if (discMsg) discount = Math.min(discount, subtotal);

        const tax = subtotal > 0 ? Math.round(rawTax * (subtotal - discount) / subtotal) : 0;
        const grand = subtotal - discount + tax;

        // Reason is mandatory only when a discount is actually given
        $('#reasonWrap').toggle(discount > 0);
        $('#discount_reason').prop('required', discount > 0);

        // Payment
        const pay = paise($('#pay_amount').val());
        const payMsg = pay > grand ? 'Payment cannot exceed the grand total.' : '';
        $('#pay_amount')[0].setCustomValidity(payMsg);
        $('#pay_amount').toggleClass('is-invalid', !!payMsg).next('.invalid-feedback').text(payMsg || '');

        const mode = modeOf($('#pay_mode').val());
        const needRef = pay > 0 && mode && mode.ref;
        $('#refWrap').toggleClass('d-none', !needRef);
        $('#pay_reference').prop('required', !!needRef);

        $('#tSubtotal').text(inr(subtotal));
        $('#tDiscount').text('− ' + inr(discount));
        $('#tTax').text(inr(tax));
        $('#tGrand').text(inr(grand));
        $('#reductionRow').toggleClass('d-none', reduced <= 0);
        $('#tReduction').text(inr(reduced));
        $('#tBalance').text(inr(Math.max(grand - pay, 0))).toggleClass('text-danger', grand - pay > 0).toggleClass('text-success', grand - pay <= 0);

        recalc.grand = grand;
    }

    // ---------- events ----------
    $body.on('select2:select change', '.js-service', function (e) {
        const $r = $(this).closest('tr');
        if (this.value === '__new__') {                       // "＋ Add a new service…"
            $(this).val('').trigger('change.select2');
            return openQuickAdd($(this), '');
        }
        const opt = this.selectedOptions[0];
        if (opt && opt.value) {
            $r.find('.js-price').val(opt.dataset.price);
            $r.find('.js-gst').val(opt.dataset.gst);
            $r.data('list', parseFloat(opt.dataset.price));
        }
        recalc();
        if (e.type === 'select2:select') $r.find('.js-qty').trigger('focus').trigger('select');
    });
    $body.on('input', 'input', function () {
        if ($(this).hasClass('js-price')) $(this).closest('tr').data('touched', true);   // the user set this price: keep it when the doctor changes
        recalc();
    });
    $body.on('click', '.js-del', function () {
        const $r = $(this).closest('tr');
        if ($r.data('auto') === 'doctor') dismissedDoctor = $('#doctor_id').val();
        $r.remove();
        if (!$body.find('.item-row').length) addRow();         // never leave the table empty
        recalc();
    });

    // Enter moves across the row; on the last field of the last row it adds a new row.
    $body.on('keydown', 'input[type=number]', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const $inputs = $(this).closest('tr').find('input[type=number]');
        const idx = $inputs.index(this);
        if (idx < $inputs.length - 1) return $inputs.eq(idx + 1).trigger('focus').trigger('select');
        const $row = $(this).closest('tr');
        const $next = $row.is(':last-child') ? addRow() : $row.next();
        recalc(); focusService($next);
    });
    $form.on('keydown', 'input:not([type=submit])', e => { if (e.key === 'Enter') e.preventDefault(); });   // never submit by accident
    $(document).on('keydown', e => { if (e.altKey && e.key.toLowerCase() === 'a') { e.preventDefault(); focusService(addRow()); } });

    $('#addRow').on('click', () => { const $r = addRow(); recalc(); focusService($r); });
    $('#doctor_id').on('change', syncDoctorFee);
    $('#discount_type, #discount_value, #pay_amount, #pay_mode').on('input change', recalc);
    $('#payFull').on('click', () => { recalc(); $('#pay_amount').val((recalc.grand / 100).toFixed(2)); recalc(); });
    $('#payLater').on('click', () => { $('#pay_amount').val(0); recalc(); });

    // A spare empty row left at the bottom is simply ignored when saving.
    $form.on('submit', () => {
        $body.find('.item-row').each(function () {
            const $r = $(this), empty = !$r.data('auto') && !$r.find('.js-service').val() && !paise($r.find('.js-price').val());
            if (empty && $body.find('.item-row').length > 1) $r.remove();
        });
        recalc();
    });

    // Confirmation before a payment is recorded (skipped for "pay later").
    window.confirmInvoice = () => {
        const pay = paise($('#pay_amount').val());
        if (pay <= 0) return null;
        return { title: 'Save the bill and record the payment?', icon: 'question', confirmText: 'Yes, save',
            text: inr(pay) + ' by ' + (modeOf($('#pay_mode').val()) || {}).label + ' will be recorded now.' };
    };

    // Segmented controls drive the hidden selects the calculation reads.
    $('#modeSeg').on('change', 'input', function () { $('#pay_mode').val(this.value).trigger('change'); });
    $('#discountSeg').on('change', 'input', function () { $('#discount_type').val(this.value).trigger('change'); });

    // "Also record today's visit": the doctor becomes required, the complaint box appears.
    function syncVisit() {
        const on = $('#create_visit').is(':checked');
        $('#visitComplaintWrap').toggleClass('d-none', !on);
        $('#doctorReq').toggleClass('d-none', !on);
        $('#doctor_id').prop('required', on);
    }
    $('#create_visit').on('change', syncVisit); syncVisit();

    // ---------- add a new service without leaving the bill ----------
    const $modal = $('#quickServiceModal').appendTo(document.body);   // out of the slide-over so it stacks correctly
    function openQuickAdd($select, name) {
        quickTarget = $select;
        $('#quickServiceForm')[0].reset();
        $('#quickServiceForm .is-invalid').removeClass('is-invalid');
        $('#qs_name').val(name);
        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
    }
    $modal.on('shown.bs.modal', () => $('#qs_name').trigger('focus'));
    $(document).on('click', '.js-quick-add', function (e) {
        e.preventDefault();
        const term = searchTerm();
        const $open = $('.js-service').filter((i, el) => $(el).data('select2') && $(el).data('select2').isOpen()).first();
        $open.select2('close');
        openQuickAdd($open, term);
    });
    $('#quickServiceForm').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this), $btn = $f.find('button[type=submit]');
        $f.find('.is-invalid').removeClass('is-invalid');
        $btn.prop('disabled', true);
        $.ajax({ url: cfg.quickUrl, method: 'POST', data: $f.serialize() })
            .done(s => {
                addServiceOption(s);
                bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
                toastr.success('“' + s.name + '” was added to your services.');
                if (quickTarget && quickTarget.length) quickTarget.val(String(s.id)).trigger('change');
                quickTarget = null;
            })
            .fail(xhr => {
                const errs = (xhr.responseJSON && xhr.responseJSON.errors) || {};
                Object.keys(errs).forEach(k => { $f.find('[name=' + k + ']').addClass('is-invalid').siblings('.invalid-feedback').text(errs[k][0]); });
                if (!Object.keys(errs).length) toastr.error('Could not add the service. Please try again.');
            })
            .always(() => $btn.prop('disabled', false));
    });

    // ---------- init ----------
    $('#invoice_date').attr('max', new Date().toISOString().slice(0, 10));
    const old = Object.values(cfg.oldItems || {});
    if (old.length) {
        old.forEach(it => {
            if (it.doctor_fee) {
                const $r = addRow(it, true);
                const d = doctors[$('#doctor_id').val()];
                $r.data('list', d ? d.fee : undefined).data('touched', true);
                $r.find('.js-auto-label').text(it.description || 'Consultation');
                $r.find('.js-auto-desc').val(it.description || '');
            } else addRow(it);
        });
    } else {
        syncDoctorFee();                                           // a doctor chosen up front (e.g. billing from a visit) adds their fee
        addRow();                                                  // and an empty row ready for the first service
    }
    $('#reasonWrap').toggle(false);
    recalc();
})();
