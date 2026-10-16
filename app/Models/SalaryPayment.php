<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryPayment extends Model
{
    use Auditable, BelongsToBranch, HasFactory, SoftDeletes, TracksUser;

    protected $fillable = ['employee_id', 'payment_date', 'for_month', 'amount', 'mode', 'reference_no', 'notes'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d', 'for_month' => 'date:Y-m-d',
            'amount' => 'decimal:2', 'mode' => PaymentMode::class,
        ];
    }

    public function auditLabel(): string
    {
        return ($this->employee?->name ?? 'Employee #'.$this->employee_id).' · '.$this->month_label;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** "Oct 2026" */
    public function getMonthLabelAttribute(): string
    {
        return $this->for_month->format('M Y');
    }
}
