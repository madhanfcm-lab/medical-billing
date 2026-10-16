<div class="modal fade" id="reschedModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog">
    <form method="POST" id="reschedForm" class="modal-content" novalidate data-loading-form>
        @csrf
        <div class="modal-header"><h5 class="modal-title">Reschedule follow-up</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-3">
            <div class="col-7">
                <label class="form-label" for="rs_date">New date <span class="text-danger">*</span></label>
                <input type="date" name="follow_up_date" id="rs_date" required min="{{ today()->toDateString() }}" value="{{ old('follow_up_date') }}" class="form-control @error('follow_up_date') is-invalid @enderror">
                <div class="invalid-feedback">{{ $errors->first('follow_up_date') ?: 'Choose today or a later date.' }}</div>
            </div>
            <div class="col-5">
                <label class="form-label" for="rs_time">Time</label>
                <input type="time" name="follow_up_time" id="rs_time" value="{{ old('follow_up_time') }}" class="form-control @error('follow_up_time') is-invalid @enderror">
                <div class="invalid-feedback">{{ $errors->first('follow_up_time') }}</div>
            </div>
            <div class="col-12">
                <label class="form-label" for="rs_doctor">Doctor</label>
                <select name="doctor_id" id="rs_doctor" class="form-select"><option value="">Keep the same doctor</option>
                    @foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
            </div>
            <div class="col-12">
                <label class="form-label" for="rs_notes">Note</label>
                <input name="notes" id="rs_notes" maxlength="2000" value="{{ old('notes') }}" class="form-control" placeholder="Optional — why it moved">
            </div>
            <div class="col-12 form-text">The original follow-up is kept as “Rescheduled” and linked to the new one.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button><button class="btn btn-primary">Reschedule</button></div>
    </form>
</div></div>

@push('scripts')
<script>
$(document).on('click', '.js-resched', function () {
    const d = new Date().toISOString().slice(0, 10), cur = $(this).data('date');
    $('#reschedForm').attr('action', $(this).data('url'));
    $('#rs_date').val(cur > d ? cur : d);
    new bootstrap.Modal('#reschedModal').show();
});
</script>
@endpush
