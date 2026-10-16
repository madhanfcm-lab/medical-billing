<?php

namespace App\Models;

use App\Enums\EmiPlanStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class EmiPlan extends Model
{
    use Auditable, BelongsToBranch, HasFactory, TracksUser;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => EmiPlanStatus::class,
            'start_date' => 'date:Y-m-d', 'first_due_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'defaulted_at' => 'datetime', 'cancelled_at' => 'datetime',
            'total_amount' => 'decimal:2', 'down_payment' => 'decimal:2', 'financed_amount' => 'decimal:2',
            'monthly_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'balance_amount' => 'decimal:2',
        ];
    }

    public function auditLabel(): string
    {
        return $this->plan_number;
    }

    public function auditEvent(array $changes): string
    {
        return match (true) {
            ($changes['status'] ?? null) === EmiPlanStatus::Cancelled->value => 'cancelled',
            ($changes['status'] ?? null) === EmiPlanStatus::Defaulted->value => 'defaulted',
            ($changes['status'] ?? null) === EmiPlanStatus::Completed->value => 'completed',
            // a new schedule changes the end date; plain payments never do
            array_key_exists('end_date', $changes) => 'rescheduled',
            ($changes['status'] ?? null) === EmiPlanStatus::Active->value => 'reactivated',
            default => 'updated',
        };
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(EmiInstallment::class)->orderBy('installment_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(EmiReschedule::class)->latest('id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Plans that still expect money (active or defaulted). */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [EmiPlanStatus::Active->value, EmiPlanStatus::Defaulted->value]);
    }

    public function canCollect(): bool
    {
        return $this->status->isOpen() && to_paise($this->balance_amount) > 0;
    }

    /** Total of instalments that are past due and unpaid, in rupees string. */
    public function overdueAmount(): string
    {
        return from_paise((int) $this->installments->sum(
            fn (EmiInstallment $i) => $i->due_date->lt(today()) ? max(to_paise($i->amount) - to_paise($i->paid_amount), 0) : 0
        ));
    }

    /**
     * Estimated finish date from the pace of payments so far; null until a month has passed
     * and something has been paid. Used to warn when the plan will overrun its end date.
     */
    public function projectedEndDate(): ?Carbon
    {
        $balance = to_paise($this->balance_amount);
        $paid = to_paise($this->paid_amount);
        if (! $this->status->isOpen() || $balance <= 0 || $paid <= 0) {
            return null;
        }
        $elapsed = max($this->first_due_date->diffInMonths(today(), false), 0);
        if ($elapsed < 1) {
            return null;
        }
        $monthsNeeded = (int) ceil($balance / ($paid / $elapsed));

        return today()->addMonthsNoOverflow($monthsNeeded);
    }

    /** Behind schedule: something is overdue, or the end date has passed with money still due. */
    public function isBehind(): bool
    {
        return $this->status->isOpen() && (to_paise($this->overdueAmount()) > 0
            || ($this->end_date->lt(today()) && to_paise($this->balance_amount) > 0));
    }
}
