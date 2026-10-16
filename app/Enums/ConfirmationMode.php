<?php

namespace App\Enums;

enum ConfirmationMode: string
{
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone call',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::InPerson => 'In person',
        };
    }
}
