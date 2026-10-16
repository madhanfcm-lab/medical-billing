<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;

class VisitPolicy
{
    public function view(User $user, Visit $visit): bool
    {
        return $user->can('visits.view') && $this->ownsOrNotDoctor($user, $visit);
    }

    public function update(User $user, Visit $visit): bool
    {
        return $user->can('visits.manage') && $this->ownsOrNotDoctor($user, $visit);
    }

    private function ownsOrNotDoctor(User $user, Visit $visit): bool
    {
        return ! $user->hasRole('doctor') || $visit->doctor_id === $user->doctorProfile?->id;
    }
}
