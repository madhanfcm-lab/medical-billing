{{-- One confirm dialog and one cancel dialog, shared by every row on the page. --}}
<div class="modal fade" id="confirmApptModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" id="confirmApptForm" class="modal-content" novalidate data-loading-form>
        @csrf
        <div class="modal-header"><h5 class="modal-title">Confirm appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-12 text-muted small">Record that <strong id="confirmApptName">the patient</strong> has agreed to this time.</div>
            <div class="col-12">
                <label class="form-label">How did they confirm? <span class="text-danger">*</span></label>
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($modes as $i => $m)
                        <input type="radio" class="btn-check" name="via" id="via_{{ $m->value }}" value="{{ $m->value }}" required @checked($i === 0)>
                        <label class="btn btn-outline-primary btn-sm" for="via_{{ $m->value }}">{{ $m->label() }}</label>
                    @endforeach
                </div>
            </div>
            <div class="col-12"><label class="form-label" for="confirmNote">Note</label>
                <input name="note" id="confirmNote" maxlength="255" class="form-control" placeholder="Optional — e.g. spoke to the patient's son"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Mark as confirmed</button></div>
    </form>
</div></div>

<div class="modal fade" id="cancelApptModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" id="cancelApptForm" class="modal-content" novalidate data-loading-form>
        @csrf
        <div class="modal-header"><h5 class="modal-title">Cancel appointment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="text-muted small mb-2">Cancel the appointment for <strong id="cancelApptName">the patient</strong>?</div>
            <label class="form-label" for="cancelReason">Reason <span class="text-danger">*</span></label>
            <input name="reason" id="cancelReason" required maxlength="255" class="form-control" placeholder="e.g. Patient asked to cancel">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger">Cancel appointment</button></div>
    </form>
</div></div>

@push('scripts')
<script>
$(document).on('click', '.js-confirm-appt', function () {
    $('#confirmApptForm').attr('action', $(this).data('url'));
    $('#confirmApptName').text($(this).data('name'));
    new bootstrap.Modal('#confirmApptModal').show();
});
$(document).on('click', '.js-cancel-appt', function () {
    $('#cancelApptForm').attr('action', $(this).data('url')).find('#cancelReason').val('');
    $('#cancelApptName').text($(this).data('name'));
    new bootstrap.Modal('#cancelApptModal').show();
});
</script>
@endpush
