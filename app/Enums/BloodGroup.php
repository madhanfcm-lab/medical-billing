<?php

namespace App\Enums;

enum BloodGroup: string
{
    case APos = 'A+';
    case ANeg = 'A-';
    case BPos = 'B+';
    case BNeg = 'B-';
    case ABPos = 'AB+';
    case ABNeg = 'AB-';
    case OPos = 'O+';
    case ONeg = 'O-';
}
