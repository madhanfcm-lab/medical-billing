/* Shared behaviour for every page. */
$.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

toastr.options = { closeButton: true, progressBar: true, positionClass: 'toast-top-center', timeOut: 4000 };

/** SweetAlert2 confirmation. Resolves true when the user confirms. */
window.confirmAction = function ({ title = 'Are you sure?', text = '', confirmText = 'Yes', icon = 'warning' } = {}) {
    return Swal.fire({ title, text, icon, showCancelButton: true, confirmButtonText: confirmText,
        confirmButtonColor: '#0f8b8d', cancelButtonColor: '#6c757d', reverseButtons: true, focusCancel: true })
        .then(r => r.isConfirmed);
};

/** Client-side validation + double-submit protection for every <form data-loading-form>. */
$(document).on('submit', 'form[data-loading-form]', function (e) {
    const form = this;
    if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
        $(form).find(':invalid').addClass('is-invalid');
        form.querySelector(':invalid')?.focus();
        return;
    }
    // Optional confirmation (payments etc.): data-confirm-fn names a window function returning
    // {title, text, confirmText} or null when no confirmation is needed.
    if (form.dataset.confirmFn && !form.dataset.confirmed) {
        const cfg = window[form.dataset.confirmFn](form);
        if (cfg) {
            e.preventDefault();
            confirmAction(cfg).then(ok => { if (ok) { form.dataset.confirmed = '1'; form.requestSubmit(); } });
            return;
        }
    }
    const pressed = e.originalEvent && e.originalEvent.submitter;
    const $btn = pressed ? $(pressed) : $(form).find('button[type=submit], button:not([type])').filter(':visible').last();
    if ($btn.data('submitting')) { e.preventDefault(); return; }
    $btn.data('submitting', true).addClass('is-loading')
        .prepend('<span class="spinner-border spinner-border-sm me-2" role="status"></span>');
});

// Instant feedback: clear the red state as soon as a field becomes valid.
$(document).on('input change', '.is-invalid', function () {
    if (this.checkValidity()) $(this).removeClass('is-invalid');
});
$(document).on('blur', 'form[data-loading-form] .form-control, form[data-loading-form] .form-select', function () {
    $(this).toggleClass('is-invalid', !this.checkValidity());
});

/** Any <button class="js-action" data-url data-method data-title data-text>: confirm, then submit via the hidden form. */
$(document).on('click', '.js-action', function () {
    const $b = $(this);
    confirmAction({ title: $b.data('title'), text: $b.data('text'), confirmText: $b.data('confirm') || 'Yes, continue' }).then(ok => {
        if (!ok) return;
        $('#actionForm').attr('action', $b.data('url')).find('[name=_method]').val($b.data('method') || 'POST').end().trigger('submit');
    });
});

/* ---- patient search (top bar and every "pick a patient" field) ---- */
const esc = t => $('<i>').text(t == null ? '' : t).html();
const tone = id => 'avatar-tone-' + ((Number(id) || 0) % 6 + 1);
const initials = n => (n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
const inrFmt = n => '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });

/** One result row: avatar, name, phone · ID · age, and a red "owes ₹x" pill when they have unpaid bills. */
window.patientResult = function (r) {
    if (r.loading) return r.text;
    if (!r.name) return r.text;
    return $(`<div class="pt-result"><span class="avatar ${tone(r.id)}">${esc(initials(r.name))}</span>
        <div><div class="pt-name">${esc(r.name)}</div><div class="pt-meta">${esc(r.phone)} · ${esc(r.uhid)}${r.meta ? ' · ' + esc(r.meta) : ''}</div></div>
        ${r.due > 0 ? `<span class="pt-due">owes ${inrFmt(r.due)}</span>` : ''}</div>`);
};
/** When nothing matches, offer to register the person using what was typed. */
window.patientNoResults = function (registerUrl) {
    return function (params) {
        // Select2 does not hand the typed text to this callback, so read it from the open search box
        const term = ($('.select2-container--open .select2-search__field').val() || '').trim();
        if (!registerUrl) return 'No patient found';
        return $(`<div class="pt-empty"><div class="text-muted mb-1">No patient found for “${esc(term)}”.</div>
            <a href="${registerUrl}?q=${encodeURIComponent(term)}"><i class="bi bi-person-plus me-1"></i>Register “${esc(term)}” as a new patient</a></div>`);
    };
};

/** Select2 bound to the patient search endpoint: <select class="js-patient-select" data-url="{{ route('patients.search') }}"> */
window.initPatientSelect = function (root) {
    $(root || document).find('.js-patient-select').each(function () {
        const $s = $(this);
        $s.select2({
            theme: 'bootstrap-5', width: '100%', placeholder: $s.data('placeholder') || 'Search by name, phone or ID…',
            minimumInputLength: 2, allowClear: true, templateResult: window.patientResult,
            language: { noResults: window.patientNoResults($s.data('register-url')), inputTooShort: () => 'Type 2 or more characters' },
            ajax: { url: $s.data('url'), dataType: 'json', delay: 250, data: p => ({ q: p.term }), processResults: d => ({ results: d.results }) },
        });
    });
};
initPatientSelect();

// Global patient search in the top bar
(function () {
    const $s = $('#globalPatientSearch');
    if (!$s.length) return;
    const url = $s.data('url');
    if (!url) { $s.prop('disabled', true); return; }
    $s.select2({
        theme: 'bootstrap-5', width: '100%', placeholder: $s.data('placeholder'), minimumInputLength: 2,
        templateResult: window.patientResult, templateSelection: () => $s.data('placeholder'),
        language: { noResults: window.patientNoResults($s.data('register-url')), inputTooShort: () => 'Type a name, phone number or ID' },
        ajax: { url, dataType: 'json', delay: 220, data: p => ({ q: p.term }), processResults: d => ({ results: d.results }) },
    }).on('select2:select', e => { window.location = $s.data('profile-url').replace('__ID__', e.params.data.id); });

    // "/" jumps to the search from anywhere (unless already typing in a field)
    $(document).on('keydown', e => {
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && !e.target.isContentEditable) {
            e.preventDefault(); $s.select2('open');
        }
    });
    $(document).on('select2:open', () => { document.querySelector('.select2-container--open .select2-search__field')?.focus(); });
})();

// A click on a menu item remembers "the mouse is on the menu" for the next page; moving away closes it again.
$('#sidebar').on('click', 'a.nav-item-link', function (e) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2) return;
    try { sessionStorage.setItem('sidebarKeepOpen', String(Date.now())); } catch (err) {}
});
(function () {
    const root = document.documentElement;
    if (!root.classList.contains('sidebar-keepopen')) return;
    const close = () => { root.classList.remove('sidebar-keepopen'); document.removeEventListener('mousemove', onMove); document.removeEventListener('touchstart', close); };
    const onMove = e => { if (!e.target.closest || !e.target.closest('#sidebar')) close(); };
    document.addEventListener('mousemove', onMove);
    document.addEventListener('touchstart', close, { passive: true });
    setTimeout(close, 6000);          // never stay open on its own for long (e.g. the mouse was not actually there)
})();

// Sidebar: a slim rail that opens on hover. "Pin" keeps it open (remembered on this computer).
(function () {
    const $pin = $('#sidebarPin');
    const paint = () => {
        const on = document.documentElement.classList.contains('sidebar-pinned');
        $pin.find('i').attr('class', 'bi ' + (on ? 'bi-pin-angle-fill' : 'bi-pin-angle'));
        $pin.find('span').text(on ? 'Unpin menu' : 'Pin menu open');
        $pin.attr('title', on ? 'Let the menu hide again' : 'Keep the menu open');
    };
    $pin.on('click', function () {
        const on = document.documentElement.classList.toggle('sidebar-pinned');
        try { localStorage.setItem('sidebarPinned', on ? '1' : '0'); } catch (e) {}
        paint();
    });
    paint();
})();

$('#sidebarToggle, #sidebarBackdrop').on('click', function () {
    $('#sidebar').toggleClass('open');
    $('#sidebarBackdrop').toggleClass('show');
});
