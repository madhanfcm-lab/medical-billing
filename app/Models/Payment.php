<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Enums\PaymentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Payments are append-only: there is no update or delete path in the app. */
class Payment extends Model
{
    use Auditable, BelongsToBranch, HasFactory, TracksUser;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'type' => PaymentType::class,
            'mode' => PaymentMode::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function auditLabel(): string
    {
        return $this->receipt_number;
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function emiPlan(): BelongsTo
    {
        return $this->belongsTo(EmiPlan::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(EmiPaymentAllocation::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
