<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/** One audited change. Rows are only ever inserted. */
class ActivityLog extends Model
{
    use BelongsToBranch;

    public $timestamps = false;

    protected $guarded = ['id'];

    /** morph alias => label shown to people */
    public const TYPES = [
        'invoice' => 'Invoice',
        'payment' => 'Payment',
        'emi_plan' => 'EMI plan',
        'attendance' => 'Attendance',
        'salary_payment' => 'Salary payment',
        'appointment' => 'Appointment',
    ];

    /** event => bootstrap badge class */
    public const EVENTS = [
        'created' => 'bg-success', 'updated' => 'bg-primary', 'cancelled' => 'bg-danger', 'deleted' => 'bg-danger',
        'defaulted' => 'bg-warning text-dark', 'completed' => 'bg-success', 'confirmed' => 'bg-success', 'checked in' => 'bg-primary', 'marked no-show' => 'bg-danger', 'rescheduled' => 'bg-info text-dark', 'reactivated' => 'bg-info text-dark',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->subject_type] ?? Str::headline($this->subject_type);
    }

    public function getEventBadgeAttribute(): string
    {
        return self::EVENTS[$this->event] ?? 'bg-secondary';
    }

    /** Where to open the audited record, when it still has a page. */
    public function subjectUrl(): ?string
    {
        $subject = $this->subject;

        return match ($this->subject_type) {
            'invoice' => route('invoices.show', $this->subject_id),
            'payment' => route('payments.receipt', $this->subject_id),
            'emi_plan' => route('emi-plans.show', $this->subject_id),
            'attendance' => $subject ? route('attendance.index', ['date' => $subject->attendance_date->toDateString()]) : null,
            'appointment' => route('appointments.show', $this->subject_id),
            'salary_payment' => $subject && ! $subject->trashed() ? route('salary-payments.edit', $this->subject_id) : null,
            default => null,
        };
    }

    /** Human friendly value for the change table. */
    public static function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        if (preg_match('/(amount|total|balance|paid|price|subtotal|payment)$/', $field) && is_numeric($value)) {
            return money($value);
        }
        if (str_ends_with($field, '_date') && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value)) {
            return format_date(substr((string) $value, 0, 10));
        }
        if ($field === 'status' || $field === 'mode') {
            return Str::headline((string) $value);
        }

        return (string) $value;
    }
}
