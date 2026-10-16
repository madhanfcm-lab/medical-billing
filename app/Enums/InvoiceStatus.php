<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Paid = green, Partial = orange, Unpaid = red, Cancelled = grey. */
    public function badge(): string
    {
        return match ($this) {
            self::Paid => 'badge-paid',
            self::PartiallyPaid => 'badge-partial',
            self::Unpaid => 'badge-unpaid',
            self::Cancelled => 'badge-cancelled',
        };
    }
}
