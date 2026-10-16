<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentType;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money in. Invoice payments live here; EMI payments (EmiPaymentService) reuse
 * issue() for receipts and applyToInvoice() when a plan is tied to an invoice.
 */
class PaymentService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /**
     * Record a payment against an invoice.
     *
     * The invoice row is locked (SELECT ... FOR UPDATE) and the balance is re-read
     * from the locked row, so two reception desks collecting at the same moment
     * can never push the total paid above the grand total.
     *
     * @param  array{amount: string|float, mode: string, reference_no?: ?string, payment_date?: ?string, notes?: ?string, batch_ref?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function collectForInvoice(Invoice $invoice, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $data) {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['amount' => 'This invoice is cancelled and cannot accept payments.']);
            }
            if ($locked->hasOpenEmiPlan()) {
                throw ValidationException::withMessages(['amount' => 'This invoice is on an EMI plan. Collect the payment through the plan.']);
            }

            $amount = to_paise($data['amount']);
            $balance = to_paise($locked->balance_amount);
            $mode = $this->validateModeAndReference($data['mode'], $data['reference_no'] ?? null);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
            }
            if ($amount > $balance) {
                throw ValidationException::withMessages(['amount' => 'Amount cannot exceed the balance of '.money($locked->balance_amount).'.']);
            }

            $payment = $this->issue([
                'patient_id' => $locked->patient_id,
                'type' => PaymentType::Invoice,
                'invoice_id' => $locked->id,
                'batch_ref' => $data['batch_ref'] ?? null,
                'amount' => from_paise($amount),
                'mode' => $mode,
                'reference_no' => $mode->requiresReference() ? $data['reference_no'] : null,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'balance_after' => from_paise($balance - $amount),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->applyToInvoice($locked, $amount);

            return $payment;
        });
    }

    /** Create the payment row with the next receipt number. Call inside a transaction. */
    public function issue(array $attributes): Payment
    {
        return Payment::create($attributes + ['receipt_number' => $this->numbers->next('receipt', (string) setting('receipt_prefix'))]);
    }

    /**
     * Add $paise to an invoice that the caller has ALREADY locked, and refresh its status.
     *
     * @throws ValidationException
     */
    public function applyToInvoice(Invoice $locked, int $paise): void
    {
        $balance = to_paise($locked->balance_amount);
        if ($paise > $balance) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds the invoice balance of '.money($locked->balance_amount).'.']);
        }

        $locked->paid_amount = from_paise(to_paise($locked->paid_amount) + $paise);
        $locked->balance_amount = from_paise($balance - $paise);
        $locked->status = $balance - $paise === 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;
        $locked->save();
    }

    /**
     * @throws ValidationException when a non-cash mode has no reference number
     */
    public function validateModeAndReference(string $mode, ?string $reference, string $field = 'reference_no'): PaymentMode
    {
        $mode = PaymentMode::from($mode);
        if ($mode->requiresReference() && blank($reference)) {
            throw ValidationException::withMessages([$field => 'Reference number is required for '.$mode->label().' payments.']);
        }

        return $mode;
    }
}
