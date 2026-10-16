<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records who created, changed or deleted a record, with the old and new values.
 * Models override auditLabel() (what to call the record) and auditEvent() (a better name than "updated").
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => app(ActivityLogger::class)->created($model));
        static::updated(fn ($model) => app(ActivityLogger::class)->updated($model));
        static::deleted(fn ($model) => app(ActivityLogger::class)->deleted($model));
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('id');
    }

    public function auditLabel(): string
    {
        return '#'.$this->getKey();
    }

    /** @param  array<string, mixed>  $changes  the changed attributes (raw values) */
    public function auditEvent(array $changes): string
    {
        return 'updated';
    }
}
