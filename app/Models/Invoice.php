<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoices are never deleted: they are cancelled with a reason.
 * Amount columns are only changed by InvoiceService / PaymentService under a row lock.
 */
class Invoice extends Model
{
    use Auditable, BelongsToBranch, HasFactory, TracksUser;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'status' => InvoiceStatus::class,
            'cancelled_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_value' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'grand_total' => 'decimal:2', 'paid_amount' => 'decimal:2', 'balance_amount' => 'decimal:2',
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

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function auditLabel(): string
    {
        return $this->invoice_number;
    }

    public function auditEvent(array $changes): string
    {
        return ($changes['status'] ?? null) === InvoiceStatus::Cancelled->value ? 'cancelled' : 'updated';
    }

    public function emiPlans(): HasMany
    {
        return $this->hasMany(EmiPlan::class);
    }

    /** While an EMI plan is open, money is collected through the plan, not directly on the invoice. */
    public function hasOpenEmiPlan(): bool
    {
        return $this->emiPlans()->open()->exists();
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    public function canCollect(): bool
    {
        return ! $this->isCancelled() && to_paise($this->balance_amount) > 0 && ! $this->hasOpenEmiPlan();
    }
}
