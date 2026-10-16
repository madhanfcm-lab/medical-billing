<?php

namespace App\Enums;

enum PaymentType: string
{
    case Invoice = 'invoice';
    case Emi = 'emi';
}
