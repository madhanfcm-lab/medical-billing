<a href="{{ $link }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="WhatsApp reminder to {{ $followUp->patient->phone }}"><i class="bi bi-whatsapp"></i></a>
@can('manage', $followUp)
    <button type="button" class="btn btn-sm btn-outline-success js-action" title="Mark completed"
            data-url="{{ route('follow-ups.complete', $followUp) }}" data-method="POST"
            data-title="Mark as completed?" data-text="{{ e($followUp->patient->name) }} — {{ e($followUp->reason) }}" data-confirm="Yes, completed"><i class="bi bi-check2-circle"></i></button>
    @can('visits.manage')
        <a href="{{ route('visits.create', ['patient' => $followUp->patient_id, 'follow_up' => $followUp->id]) }}" class="btn btn-sm btn-outline-primary" title="Complete and record a new visit"><i class="bi bi-clipboard2-plus"></i></a>
    @endcan
    <button type="button" class="btn btn-sm btn-outline-secondary js-resched" title="Reschedule"
            data-url="{{ route('follow-ups.reschedule', $followUp) }}" data-date="{{ $followUp->follow_up_date->toDateString() }}"><i class="bi bi-calendar2-week"></i></button>
    <button type="button" class="btn btn-sm btn-outline-danger js-action" title="Cancel"
            data-url="{{ route('follow-ups.cancel', $followUp) }}" data-method="POST"
            data-title="Cancel this follow-up?" data-text="{{ e($followUp->patient->name) }} — {{ format_date($followUp->follow_up_date) }}" data-confirm="Yes, cancel it"><i class="bi bi-x-circle"></i></button>
@endcan
