<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\ConfirmationMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use Auditable, BelongsToBranch, TracksUser;

    protected $guarded = ['id', 'booking_number', 'branch_id'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'confirmed_via' => ConfirmationMode::class,
            'appointment_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function getStartsAtAttribute(): Carbon
    {
        return Carbon::parse($this->appointment_date->toDateString().' '.$this->appointment_time);
    }

    public function getEndsAtAttribute(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    /** "10:30 AM" */
    public function getTimeLabelAttribute(): string
    {
        return $this->starts_at->format('h:i A');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', AppointmentStatus::openValues());
    }

    public function scopeOnDate(Builder $q, string $date): Builder
    {
        return $q->whereDate('appointment_date', $date);
    }

    /** Booked but nobody has rung the patient yet, and the day has not passed. */
    public function scopeNeedsConfirmation(Builder $q): Builder
    {
        return $q->where('status', AppointmentStatus::Scheduled->value)->whereDate('appointment_date', '>=', today()->toDateString());
    }

    /** Doctors only see their own appointments. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->hasRole('doctor') ? $q->where('appointments.doctor_id', $user->doctorProfile?->id ?? 0) : $q;
    }

    /** Still open and its day has passed: the patient did not come. */
    public function isOverdue(): bool
    {
        return $this->status->isOpen() && $this->appointment_date->lt(today());
    }

    public function auditLabel(): string
    {
        return $this->booking_number;
    }

    /** @param  array<string, mixed>  $changes */
    public function auditEvent(array $changes): string
    {
        return match ($changes['status'] ?? null) {
            AppointmentStatus::Confirmed->value => 'confirmed',
            AppointmentStatus::Cancelled->value => 'cancelled',
            AppointmentStatus::CheckedIn->value => 'checked in',
            AppointmentStatus::NoShow->value => 'marked no-show',
            default => 'updated',
        };
    }
}
