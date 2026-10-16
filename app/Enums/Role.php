<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Receptionist = 'receptionist';
    case Doctor = 'doctor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Receptionist => 'Receptionist / Billing',
            self::Doctor => 'Doctor',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
