<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.view')
            && Patient::query()->visibleTo($user)->whereKey($invoice->patient_id)->exists();
    }

    public function collect(User $user, Invoice $invoice): bool
    {
        return $user->can('payments.collect') && $invoice->canCollect();
    }

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.cancel') && ! $invoice->isCancelled() && ! $invoice->hasOpenEmiPlan();
    }
}
