<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Keeps a record inside one branch: every query is limited to the branch the user is working in, and new records are
 * stamped with it. Because the filter lives here, lists, reports, the dashboard and route-model binding all respect it
 * without each one remembering to.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            $id = app(CurrentBranch::class)->filterId();
            if ($id !== null) {
                $query->where($query->getModel()->getTable().'.branch_id', $id);
            }
        });

        static::creating(function ($model) {
            $model->branch_id ??= app(CurrentBranch::class)->writeId();
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
