<?php

namespace App\Services;

use App\Enums\EmiPlanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentType;
use App\Models\EmiInstallment;
use App\Models\EmiPaymentAllocation;
use App\Models\EmiPlan;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmiPaymentService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Accept ANY amount (less, equal or more than the instalment) up to the plan balance.
     * The money is allocated to the oldest unpaid instalment first and the excess carries
     * into the next ones. Paying the whole balance is an early settlement.
     *
     * The plan row is locked first, then its open instalments (and the invoice, if the plan is
     * tied to one), so concurrent desks cannot double-allocate the same rupees.
     *
     * @param  array{amount: string|float, mode: string, reference_no?: ?string, payment_date?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function collect(EmiPlan $plan, array $data): Payment
    {
        return DB::transaction(function () use ($plan, $data) {
            $locked = EmiPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages(['amount' => 'This EMI plan is '.$locked->status->label().' and cannot accept payments.']);
            }

            $amount = to_paise($data['amount']);
            $balance = to_paise($locked->balance_amount);
            $mode = $this->payments->validateModeAndReference($data['mode'], $data['reference_no'] ?? null);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
            }
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Amount cannot exceed the plan balance of '.money($locked->balance_amount).'.']);
            }

            $payment = $this->payments->issue([
                'patient_id' => $locked->patient_id,
                'type' => PaymentType::Emi,
                'invoice_id' => $locked->invoice_id,
                'emi_plan_id' => $locked->id,
                'amount' => from_paise($amount),
                'mode' => $mode,
                'reference_no' => $mode->requiresReference() ? $data['reference_no'] : null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'balance_after' => from_paise($balance - $amount),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->allocate($locked, $payment, $amount);

            $locked->paid_amount = from_paise(to_paise($locked->paid_amount) + $amount);
            $locked->balance_amount = from_paise($balance - $amount);
            if ($balance - $amount === 0) {
                $locked->status = EmiPlanStatus::Completed;
            }
            $locked->save();

            if ($locked->invoice_id) {
                $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();
                $this->payments->applyToInvoice($invoice, $amount);
            }

            return $payment;
        });
    }

    /** Oldest-first allocation across unpaid instalments. */
    private function allocate(EmiPlan $plan, Payment $payment, int $amount): void
    {
        $remaining = $amount;

        $open = EmiInstallment::query()->where('emi_plan_id', $plan->id)->unpaid()
            ->orderBy('due_date')->orderBy('installment_no')->lockForUpdate()->get();

        foreach ($open as $installment) {
            if ($remaining === 0) {
                break;
            }
            $due = to_paise($installment->amount) - to_paise($installment->paid_amount);
            $take = min($due, $remaining);
            $paid = to_paise($installment->paid_amount) + $take;

            $installment->paid_amount = from_paise($paid);
            $installment->status = InstallmentStatus::resolve(to_paise($installment->amount), $paid, $installment->due_date);
            if ($installment->status === InstallmentStatus::Paid) {
                $installment->paid_on = $payment->payment_date;
            }
            $installment->save();

            EmiPaymentAllocation::create([
                'payment_id' => $payment->id,
                'emi_installment_id' => $installment->id,
                'amount' => from_paise($take),
                'created_at' => now(),
            ]);
            $remaining -= $take;
        }

        // The plan balance equals the sum of instalment balances, so this can only fail on corrupt data.
        if ($remaining !== 0) {
            throw ValidationException::withMessages(['amount' => 'Payment could not be fully allocated. Please contact the administrator.']);
        }
    }
}
