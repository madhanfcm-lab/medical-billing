<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmiInstallment extends Model
{
    protected $guarded = ['id'];

    /** An instalment belongs to the branch of its plan. */
    protected static function booted(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            if (app(CurrentBranch::class)->filterId() !== null) {
                $query->whereIn('emi_installments.emi_plan_id', EmiPlan::query()->select('emi_plans.id'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InstallmentStatus::class,
            'due_date' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d',
            'amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(EmiPlan::class, 'emi_plan_id');
    }

    public function getBalanceAttribute(): string
    {
        return from_paise(max(to_paise($this->amount) - to_paise($this->paid_amount), 0));
    }

    /** Always-correct status for display (the stored one is refreshed daily by emi:mark-overdue). */
    public function getEffectiveStatusAttribute(): InstallmentStatus
    {
        return InstallmentStatus::resolve(to_paise($this->amount), to_paise($this->paid_amount), $this->due_date);
    }

    public function scopeUnpaid(Builder $q): Builder
    {
        return $q->whereColumn('emi_installments.paid_amount', '<', 'emi_installments.amount');
    }

    /** Unpaid, past due, on a plan that still expects money. */
    public function scopeOverdue(Builder $q): Builder
    {
        return $q->unpaid()->whereDate('due_date', '<', today()->toDateString())
            ->whereHas('plan', fn ($p) => $p->open());
    }

    public function scopeUpcoming(Builder $q, int $days = 7): Builder
    {
        return $q->unpaid()
            ->whereDate('due_date', '>=', today()->toDateString())
            ->whereDate('due_date', '<=', today()->addDays($days)->toDateString())
            ->whereHas('plan', fn ($p) => $p->where('status', 'active'));
    }
}
