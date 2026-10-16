<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmiReschedule extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_schedule' => 'array', 'created_at' => 'datetime', 'new_start_date' => 'date:Y-m-d',
            'balance_at_time' => 'decimal:2', 'new_monthly_amount' => 'decimal:2'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
