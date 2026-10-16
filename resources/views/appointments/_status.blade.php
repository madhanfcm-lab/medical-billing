@php $s = $appointment->status; @endphp
@if ($appointment->isOverdue())
    <span class="badge badge-unpaid">Not attended</span>
@else
    <span class="badge {{ $s->badge() }}">{{ $s->label() }}</span>
@endif
@if ($s === \App\Enums\AppointmentStatus::Confirmed && $appointment->confirmed_via)
    <div class="small text-muted"><i class="bi bi-check2"></i> {{ $appointment->confirmed_via->label() }}</div>
@endif
