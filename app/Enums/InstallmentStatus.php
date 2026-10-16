<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum InstallmentStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially Paid',
            default => ucfirst($this->value),
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Paid => 'badge-paid',
            self::PartiallyPaid => 'badge-partial',
            self::Overdue => 'badge-overdue',
            self::Pending => 'bg-secondary',
        };
    }

    /** The one place that decides an instalment's status (amounts in paise). */
    public static function resolve(int $amount, int $paid, CarbonInterface $due, ?CarbonInterface $today = null): self
    {
        $today ??= today();

        return match (true) {
            $paid >= $amount => self::Paid,
            $due->copy()->startOfDay()->lt($today->copy()->startOfDay()) => self::Overdue,
            $paid > 0 => self::PartiallyPaid,
            default => self::Pending,
        };
    }
}
