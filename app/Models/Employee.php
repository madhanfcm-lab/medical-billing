<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Employee extends Model
{
    use BelongsToBranch, HasFactory, SoftDeletes, TracksUser;

    protected $fillable = ['branch_id', 'user_id', 'employee_code', 'name', 'designation', 'phone', 'joining_date', 'is_active'];

    protected function casts(): array
    {
        return ['joining_date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    /** The login this person uses, if any. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Already employed on $date. */
    public function scopeJoinedBy(Builder $q, Carbon $date): Builder
    {
        return $q->whereDate('joining_date', '<=', $date->toDateString());
    }

    /** Next free code like EMP-007, offered as the default in the form. */
    public static function suggestCode(): string
    {
        $n = (int) static::withoutGlobalScope('branch')->withTrashed()->max('id') + 1;
        while (static::withoutGlobalScope('branch')->withTrashed()->where('employee_code', sprintf('EMP-%03d', $n))->exists()) {
            $n++;
        }

        return sprintf('EMP-%03d', $n);
    }
}
