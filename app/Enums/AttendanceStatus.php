<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case HalfDay = 'half_day';
    case Leave = 'leave';

    public function label(): string
    {
        return match ($this) {
            self::HalfDay => 'Half Day',
            default => ucfirst($this->value),
        };
    }

    /** Letter used in the monthly grid. */
    public function code(): string
    {
        return match ($this) {
            self::Present => 'P',
            self::Absent => 'A',
            self::HalfDay => 'HD',
            self::Leave => 'L',
        };
    }

    /** Bootstrap colour name. */
    public function tone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Absent => 'danger',
            self::HalfDay => 'warning',
            self::Leave => 'info',
        };
    }
}
