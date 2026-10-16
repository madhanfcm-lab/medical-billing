<?php

namespace App\Models;

use App\Enums\ServiceCategory;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory, SoftDeletes, TracksUser;

    protected $fillable = ['name', 'category', 'default_price', 'gst_percent', 'is_active'];

    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'default_price' => 'decimal:2',
            'gst_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public const CONSULTATION_NAME = 'Doctor consultation';

    /**
     * The catalogue entry that carries every doctor-fee line on a bill. Its own price is 0 on purpose:
     * the real price comes from the selected doctor's consultation fee, per bill line.
     */
    public static function doctorConsultation(): self
    {
        $service = static::withTrashed()->firstOrCreate(
            ['name' => self::CONSULTATION_NAME, 'category' => ServiceCategory::Consultation->value],
            ['default_price' => 0, 'gst_percent' => 0, 'is_active' => true],
        );

        // someone may have deactivated or deleted it in the catalogue: bills still need it
        if ($service->trashed()) {
            $service->restore();
        }
        if (! $service->is_active) {
            $service->update(['is_active' => true]);
        }

        return $service;
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
