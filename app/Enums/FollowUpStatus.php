<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Scheduled => 'bg-primary',
            self::Completed => 'badge-paid',
            self::Missed => 'badge-unpaid',
            self::Cancelled => 'badge-cancelled',
            self::Rescheduled => 'badge-partial',
        };
    }

    /** Still waiting to be dealt with: can be completed, rescheduled or cancelled. */
    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::Missed;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Scheduled->value, self::Missed->value];
    }
}
