<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use BelongsToBranch, HasFactory, TracksUser;

    protected $fillable = ['patient_id', 'doctor_id', 'visit_date', 'chief_complaint', 'diagnosis', 'treatment_given', 'notes'];

    protected function casts(): array
    {
        return ['visit_date' => 'date:Y-m-d'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class)->withTrashed();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
