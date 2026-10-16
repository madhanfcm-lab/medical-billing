<?php

namespace App\Models;

use App\Enums\FollowUpStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FollowUp extends Model
{
    use BelongsToBranch, HasFactory, TracksUser;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => FollowUpStatus::class,
            'follow_up_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function emiPlan(): BelongsTo
    {
        return $this->belongsTo(EmiPlan::class);
    }

    /** The visit that was opened when this follow-up was completed. */
    public function completedVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'completed_visit_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledTo(): HasOne
    {
        return $this->hasOne(self::class, 'rescheduled_from_id');
    }

    /** "10:30 AM", or null when no time was set. */
    public function getTimeLabelAttribute(): ?string
    {
        return $this->follow_up_time ? date('h:i A', strtotime($this->follow_up_time)) : null;
    }

    /** Scheduled follow-ups for today. */
    public function scopeToday(Builder $q): Builder
    {
        return $q->where('status', FollowUpStatus::Scheduled->value)->whereDate('follow_up_date', today()->toDateString());
    }

    /** Scheduled, after today. */
    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('status', FollowUpStatus::Scheduled->value)->whereDate('follow_up_date', '>', today()->toDateString());
    }

    /** Missed, plus scheduled ones already in the past that the daily job has not flagged yet. */
    public function scopeMissed(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('status', FollowUpStatus::Missed->value)
            ->orWhere(fn ($s) => $s->where('status', FollowUpStatus::Scheduled->value)->whereDate('follow_up_date', '<', today()->toDateString())));
    }

    /** Only follow-ups of patients the user may see (doctors: their own patients). */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->hasRole('doctor')
            ? $q->whereIn('follow_ups.patient_id', Patient::query()->visibleTo($user)->select('patients.id'))
            : $q;
    }

    /** Past its date and still open, whether or not the daily job has marked it Missed yet. */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->follow_up_date->lt(today());
    }
}
