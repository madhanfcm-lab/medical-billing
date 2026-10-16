<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function view(User $user, Patient $patient): bool
    {
        return $user->can('patients.view')
            && Patient::query()->visibleTo($user)->whereKey($patient->getKey())->exists();
    }
}
