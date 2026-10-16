<?php

namespace App\Models;

use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use HasFactory, SoftDeletes, TracksUser;

    protected $fillable = ['department_id', 'user_id', 'name', 'specialization', 'phone', 'consultation_fee', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'consultation_fee' => 'decimal:2'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Login account of this doctor (so a Doctor user only sees their own patients). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
