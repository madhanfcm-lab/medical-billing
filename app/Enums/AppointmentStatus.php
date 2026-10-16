<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Awaiting confirmation',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Arrived',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Scheduled => 'badge-partial',
            self::Confirmed => 'badge-paid',
            self::CheckedIn => 'bg-primary',
            self::Cancelled => 'badge-cancelled',
            self::NoShow => 'badge-unpaid',
        };
    }

    /** Still to happen: can be confirmed, moved, cancelled or checked in. */
    public function isOpen(): bool
    {
        return $this === self::Scheduled || $this === self::Confirmed;
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Scheduled->value, self::Confirmed->value];
    }
}
