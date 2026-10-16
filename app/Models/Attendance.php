<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\TracksUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use Auditable, BelongsToBranch, HasFactory, TracksUser;

    protected $fillable = ['employee_id', 'attendance_date', 'status', 'remark'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date:Y-m-d', 'status' => AttendanceStatus::class];
    }

    public function auditLabel(): string
    {
        return ($this->employee?->name ?? 'Employee #'.$this->employee_id).' · '.format_date($this->attendance_date);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
