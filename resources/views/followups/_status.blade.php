@php $s = $followUp->status; @endphp
@if ($s === \App\Enums\FollowUpStatus::Scheduled && $followUp->isOverdue())
    <span class="badge {{ \App\Enums\FollowUpStatus::Missed->badge() }}">Missed</span>
@else
    <span class="badge {{ $s->badge() }}">{{ $s->label() }}</span>
@endif
@if ($s === \App\Enums\FollowUpStatus::Rescheduled && $followUp->relationLoaded('rescheduledTo') && $followUp->rescheduledTo)
    <div class="small text-muted">→ <a href="{{ route('follow-ups.show', $followUp->rescheduledTo) }}">{{ format_date($followUp->rescheduledTo->follow_up_date) }}</a></div>
@endif
