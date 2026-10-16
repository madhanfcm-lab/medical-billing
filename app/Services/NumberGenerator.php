<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Issues gap-free, never-duplicated document numbers (UHID, invoice, receipt).
 *
 * A sequence row per (type, year) is locked with SELECT ... FOR UPDATE inside a
 * transaction, so two concurrent requests are serialised and can never get the
 * same number. When called inside a caller's transaction the lock is held until
 * that outer transaction commits, which is what we want.
 */
class NumberGenerator
{
    public function next(string $type, string $prefix, ?int $year = null): string
    {
        $year ??= now()->year;

        return DB::transaction(function () use ($type, $prefix, $year) {
            DB::table('number_sequences')->insertOrIgnore(['type' => $type, 'year' => $year, 'last_number' => 0]);

            $row = DB::table('number_sequences')
                ->where('type', $type)->where('year', $year)
                ->lockForUpdate()->first();

            $next = $row->last_number + 1;
            DB::table('number_sequences')->where('id', $row->id)->update(['last_number' => $next]);

            return sprintf('%s-%d-%05d', $prefix, $year, $next);
        });
    }
}
