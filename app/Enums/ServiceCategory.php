<?php

namespace App\Enums;

enum ServiceCategory: string
{
    case Consultation = 'consultation';
    case Procedure = 'procedure';
    case Lab = 'lab';
    case Pharmacy = 'pharmacy';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
