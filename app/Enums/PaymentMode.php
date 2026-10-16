<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Upi => 'UPI',
            self::BankTransfer => 'Bank Transfer',
            default => ucfirst($this->value),
        };
    }

    public function requiresReference(): bool
    {
        return $this !== self::Cash;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
