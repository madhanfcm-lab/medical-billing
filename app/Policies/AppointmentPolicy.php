<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.view')
            && (! $user->hasRole('doctor') || $appointment->doctor_id === $user->doctorProfile?->id);
    }

    /** Confirm, move, cancel, check in: only while the appointment is still open. */
    public function manage(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.manage') && $appointment->status->isOpen();
    }
}
