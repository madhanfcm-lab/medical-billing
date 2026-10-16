/*
 * Live EMI schedule preview, shared by "New plan" and "Reschedule".
 * The schedule is always calculated by the server (App\Services\EmiScheduleGenerator),
 * so what is previewed is exactly what gets saved.
 */
window.EmiPreview = (function () {
    function bind($form, opts) {
        const q = sel => $form.find(sel);
        const $by = () => $form.find('[name=calc_by]:checked').val();
        let timer = null, xhr = null;

        function toggle() {
            const byMonths = $by() === 'months';
            q('[name=months]').prop('disabled', !byMonths).prop('required', byMonths);
            q('[name=monthly_amount]').prop('disabled', byMonths).prop('required', !byMonths);
        }

        function render(data) {
            const $tb = (opts.table || q('#pvTable')).find('tbody').empty();
            data.installments.forEach(i => $tb.append(`<tr><td>${i.no}</td><td>${i.due_date}</td><td class="text-end">${i.amount_fmt}</td></tr>`));
            // the counterpart field shows the derived value
            if ($by() === 'months') q('[name=monthly_amount]').val(data.monthly_amount);
            else q('[name=months]').val(data.months);
            (opts.summary || $form).find('#sCount').text(data.months);
            (opts.summary || $form).find('#sMonthly').text(data.monthly_amount_fmt);
            (opts.summary || $form).find('#sFirst').text(data.first_due_date);
            (opts.summary || $form).find('#sEnd').text(data.end_date);
            (opts.summary || $form).find('#pvSummary').removeClass('d-none');
            (opts.summary || $form).find('#pvMsg').addClass('d-none');
            if (opts.onSummary) opts.onSummary(data);
        }

        function message(text) {
            (opts.table || q('#pvTable')).find('tbody').empty();
            (opts.summary || $form).find('#pvSummary').addClass('d-none');
            (opts.summary || $form).find('#pvMsg').removeClass('d-none').text(text);
        }

        function refresh() {
            const byMonths = $by() === 'months';
            const amount = opts.amount();
            const value = byMonths ? q('[name=months]').val() : q('[name=monthly_amount]').val();
            if (!(amount > 0)) return message(opts.emptyMessage || 'Enter the total amount first.');
            if (!value || !q('[name=start_date]').val() || !q('[name=due_day]').val()) return message('Fill in the instalment details to see the schedule.');

            if (xhr) xhr.abort();
            xhr = $.ajax({
                url: EMI_CFG.previewUrl, method: 'POST',
                data: { amount, calc_by: $by(), months: byMonths ? value : null, monthly_amount: byMonths ? null : value,
                    start_date: q('[name=start_date]').val(), due_day: q('[name=due_day]').val(), first_number: opts.firstNumber || 1 },
            }).done(render).fail((r, status) => {
                if (status === 'abort') return;
                const errors = r.responseJSON && r.responseJSON.errors;
                message(errors ? Object.values(errors)[0][0] : 'Could not calculate the schedule.');
            });
        }
        const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };

        q('[name=calc_by]').on('change', () => { toggle(); later(); });
        q('[name=months], [name=monthly_amount], [name=start_date], [name=due_day]').on('input change', later);
        (opts.watch || $()).on('input change', later);
        $form.find('#total_amount, #down_payment').on('input change', later);

        toggle();
        refresh();
        return { refresh };
    }
    return { bind };
})();
