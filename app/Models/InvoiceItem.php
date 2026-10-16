<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'list_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'gst_percent' => 'decimal:2',
            'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    /** How much per unit this line was sold below the standard price (0 when at or above it, or unknown). */
    public function getReductionAttribute(): string
    {
        return $this->list_price === null ? '0.00' : from_paise(max(to_paise($this->list_price) - to_paise($this->unit_price), 0) * (int) $this->quantity);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
