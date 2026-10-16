<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value application settings. The whole table is cached as one array
 * and flushed on every write, so reads cost nothing after the first hit.
 */
class Setting extends Model
{
    private const CACHE_KEY = 'app_settings';

    protected $fillable = ['key', 'value'];

    /** Keys the Settings screen manages, with their defaults. */
    public const DEFAULTS = [
        'hospital_name' => 'My Clinic',
        'hospital_logo' => null,
        'login_image' => null,
        'hospital_address' => null,
        'hospital_phone' => null,
        'hospital_email' => null,
        'hospital_gstin' => null,
        'invoice_prefix' => 'INV',
        'receipt_prefix' => 'RCP',
        'patient_prefix' => 'PAT',
        'invoice_footer' => 'Thank you. Get well soon!',
    ];

    /** @return array<string, mixed> */
    public static function cached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::cached()[$key] ?? null;

        return $value !== null && $value !== '' ? $value : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    /** @param  array<string, mixed>  $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget(self::CACHE_KEY);
    }
}
