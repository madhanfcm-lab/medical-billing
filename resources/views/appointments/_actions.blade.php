@php $link = \App\Support\Whatsapp::link($appointment->patient->phone, \App\Support\Whatsapp::appointment($appointment)); @endphp
<a href="{{ $link }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="WhatsApp {{ $appointment->status === \App\Enums\AppointmentStatus::Confirmed ? 'confirmation' : 'to ask them to confirm' }}"><i class="bi bi-whatsapp"></i></a>
@can('manage', $appointment)
    @if ($appointment->status === \App\Enums\AppointmentStatus::Scheduled)
        <button type="button" class="btn btn-sm btn-success js-confirm-appt" title="Patient confirmed"
                data-url="{{ route('appointments.confirm', $appointment) }}" data-name="{{ $appointment->patient->name }}"><i class="bi bi-check2-circle me-1"></i>Confirm</button>
    @endif
    @if ($appointment->appointment_date->isToday())
        <button type="button" class="btn btn-sm btn-primary js-action" title="Patient has arrived"
                data-url="{{ route('appointments.check-in', $appointment) }}" data-method="POST"
                data-title="Check in {{ e($appointment->patient->name) }}?" data-text="This opens today's visit with {{ e($appointment->doctor->name) }}." data-confirm="Yes, check in"><i class="bi bi-box-arrow-in-right me-1"></i>Arrived</button>
    @endif
    <a href="{{ route('appointments.edit', $appointment) }}" class="btn btn-sm btn-outline-secondary" title="Change time or doctor"><i class="bi bi-pencil"></i></a>
    <button type="button" class="btn btn-sm btn-outline-danger js-cancel-appt" title="Cancel"
            data-url="{{ route('appointments.cancel', $appointment) }}" data-name="{{ $appointment->patient->name }}"><i class="bi bi-x-circle"></i></button>
@endcan
