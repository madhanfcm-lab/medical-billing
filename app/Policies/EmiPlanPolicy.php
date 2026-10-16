<?php

namespace App\Policies;

use App\Enums\EmiPlanStatus;
use App\Models\EmiPlan;
use App\Models\Patient;
use App\Models\User;

class EmiPlanPolicy
{
    public function view(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.view') && Patient::query()->visibleTo($user)->whereKey($plan->patient_id)->exists();
    }

    public function collect(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.manage') && $plan->canCollect();
    }

    public function reschedule(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.reschedule') && $plan->canCollect();
    }

    public function cancel(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.cancel') && $plan->status->isOpen();
    }

    public function markDefaulted(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.cancel') && $plan->status === EmiPlanStatus::Active && $plan->canCollect();
    }

    public function reactivate(User $user, EmiPlan $plan): bool
    {
        return $user->can('emi.cancel') && $plan->status === EmiPlanStatus::Defaulted;
    }
}
