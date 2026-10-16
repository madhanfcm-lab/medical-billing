<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/** Fills created_by / updated_by from the signed-in user. */
trait TracksUser
{
    public static function bootTracksUser(): void
    {
        static::creating(function ($model) {
            $model->created_by ??= Auth::id();
            $model->updated_by ??= Auth::id();
        });
        static::updating(function ($model) {
            $model->updated_by = Auth::id() ?? $model->updated_by;
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
