<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\User;

class FollowUpPolicy
{
    public function view(User $user, FollowUp $followUp): bool
    {
        return $user->can('followups.view') && $this->canSeePatient($user, $followUp);
    }

    /** Complete, reschedule or cancel: only while the follow-up is still open. */
    public function manage(User $user, FollowUp $followUp): bool
    {
        return $user->can('followups.manage') && $followUp->status->isOpen() && $this->canSeePatient($user, $followUp);
    }

    private function canSeePatient(User $user, FollowUp $followUp): bool
    {
        return Patient::query()->visibleTo($user)->whereKey($followUp->patient_id)->exists();
    }
}
