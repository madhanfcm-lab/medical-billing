<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $user->can('payments.collect')
            || ($user->can('invoices.view') && Patient::query()->visibleTo($user)->whereKey($payment->patient_id)->exists());
    }
}
