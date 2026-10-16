<?php

namespace App\Enums;

enum EmiPlanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Defaulted = 'defaulted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'bg-primary',
            self::Completed => 'badge-paid',
            self::Defaulted => 'badge-unpaid',
            self::Cancelled => 'badge-cancelled',
        };
    }

    /** Plans that still expect money. */
    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::Defaulted;
    }
}
