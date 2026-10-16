<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Receive payment" from the patient page: one amount from the patient, applied to their unpaid bills
 * oldest first. Each bill still gets its own receipt row (so every bill's history stays exact); the rows
 * share a batch_ref so a single combined receipt can be printed for the one handover of cash.
 */
class PatientPaymentService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Bills money can be taken against, oldest first. Bills that belong to an open EMI plan are left out:
     * those are paid through the plan.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoices(Patient $patient, bool $lock = false): Collection
    {
        $query = Invoice::query()
            ->where('patient_id', $patient->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('balance_amount', '>', 0)
            ->whereDoesntHave('emiPlans', fn ($q) => $q->open())
            ->orderBy('invoice_date')->orderBy('id');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /** Total that "Receive payment" can take right now, as "1234.50". */
    public function totalDue(Patient $patient): string
    {
        return from_paise($this->openInvoices($patient)->sum(fn (Invoice $i) => to_paise($i->balance_amount)));
    }

    /**
     * @param  array{amount: string|float, mode: string, reference_no?: ?string, payment_date?: ?string, notes?: ?string}  $data
     * @return array{batch_ref: string, payments: Collection<int, Payment>}
     *
     * @throws ValidationException
     */
    public function collect(Patient $patient, array $data): array
    {
        $mode = $this->payments->validateModeAndReference($data['mode'], $data['reference_no'] ?? null);
        $amount = to_paise($data['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
        }

        return DB::transaction(function () use ($patient, $data, $mode, $amount) {
            $invoices = $this->openInvoices($patient, lock: true);
            $due = $invoices->sum(fn (Invoice $i) => to_paise($i->balance_amount));

            if ($amount > $due) {
                throw ValidationException::withMessages(['amount' => $due === 0
                    ? 'This patient has no unpaid bills.'
                    : 'Amount cannot exceed the total due of '.money(from_paise($due)).'.']);
            }

            $batch = (string) Str::uuid();
            $remaining = $amount;
            $made = collect();

            foreach ($invoices as $invoice) {
                if ($remaining === 0) {
                    break;
                }
                $take = min($remaining, to_paise($invoice->balance_amount));
                $made->push($this->payments->collectForInvoice($invoice, [
                    'amount' => from_paise($take),
                    'mode' => $mode->value,
                    'reference_no' => $data['reference_no'] ?? null,
                    'payment_date' => $data['payment_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'batch_ref' => $batch,
                ]));
                $remaining -= $take;
            }

            return ['batch_ref' => $batch, 'payments' => $made];
        });
    }
}
