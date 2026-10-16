<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit trail. Used by the Auditable trait, so every save of an audited model is recorded
 * in the same transaction as the change itself: no change without a log line, no log line without a change.
 */
class ActivityLogger
{
    /** Housekeeping columns that are not worth a log line. */
    private const IGNORED = ['created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at', 'branch_id'];

    public function created(Model $model): void
    {
        $values = array_filter(
            array_diff_key($model->getAttributes(), array_flip(self::IGNORED)),
            fn ($v) => $v !== null,
        );
        $this->write($model, 'created', null, $values);
    }

    public function updated(Model $model): void
    {
        $changes = array_diff_key($model->getChanges(), array_flip(self::IGNORED));
        if ($changes === []) {
            return;
        }

        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getRawOriginal($key);
        }
        $this->write($model, $model->auditEvent($changes), $old, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', null, null);
    }

    private function write(Model $model, string $event, ?array $old, ?array $new): void
    {
        $alias = $model->getMorphClass();
        $label = $model->auditLabel();
        $type = ActivityLog::TYPES[$alias] ?? $alias;

        ActivityLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'subject_type' => $alias,
            'subject_id' => $model->getKey(),
            'subject_label' => mb_substr($label, 0, 150),
            'description' => "{$type} {$label} {$event}",
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
