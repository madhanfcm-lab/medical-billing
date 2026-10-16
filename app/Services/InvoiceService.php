<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly NumberGenerator $numbers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Create an invoice with its line items and, optionally, the payment made at billing time.
     * Everything happens in one transaction: a failed payment leaves no half-created invoice.
     *
     * @param  array<string, mixed>  $data  Validated input (see StoreInvoiceRequest)
     */
    public function create(array $data): Invoice
    {
        $calc = $this->calculator->calculate($data['items'], $data['discount_type'] ?? null, $data['discount_value'] ?? null);

        return DB::transaction(function () use ($data, $calc) {
            $hasDiscount = to_paise($calc['discount_amount']) > 0;
            $grand = to_paise($calc['grand_total']);

            $services = Service::withTrashed()->whereIn('id', collect($data['items'])->pluck('service_id'))->get()->keyBy('id');

            // "Record today's visit" while billing: the doctor and treatment come from the bill itself.
            $visitId = $data['visit_id'] ?? null;
            if (! $visitId && ! empty($data['create_visit'])) {
                $visitId = Visit::create([
                    'patient_id' => $data['patient_id'],
                    'doctor_id' => $data['doctor_id'],
                    'visit_date' => $data['invoice_date'],
                    'chief_complaint' => filled($data['visit_complaint'] ?? null) ? $data['visit_complaint'] : 'Consultation / treatment',
                    'treatment_given' => collect($data['items'])->map(fn ($i) => $services[$i['service_id']]->name)->unique()->implode(', '),
                ])->id;
            }

            $invoice = Invoice::create([
                'invoice_number' => $this->numbers->next('invoice', (string) setting('invoice_prefix')),
                'patient_id' => $data['patient_id'],
                'visit_id' => $visitId,
                'doctor_id' => $data['doctor_id'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'subtotal' => $calc['subtotal'],
                'discount_type' => $hasDiscount ? $data['discount_type'] : null,
                'discount_value' => $hasDiscount ? $data['discount_value'] : 0,
                'discount_amount' => $calc['discount_amount'],
                'discount_reason' => $hasDiscount ? $data['discount_reason'] : null,
                'tax_amount' => $calc['tax_amount'],
                'grand_total' => $calc['grand_total'],
                'paid_amount' => 0,
                'balance_amount' => $calc['grand_total'],
                'status' => $grand === 0 ? InvoiceStatus::Paid : InvoiceStatus::Unpaid,
                'notes' => $data['notes'] ?? null,
            ]);

            $doctorFee = ! empty($data['doctor_id']) ? Doctor::withTrashed()->find($data['doctor_id'])?->consultation_fee : null;

            foreach ($data['items'] as $i => $item) {
                // A doctor-fee line is named after the doctor and its standard price is that doctor's fee;
                // every other line uses the catalogue name and the catalogue price.
                $isDoctorFee = ! empty($item['doctor_fee']);
                $invoice->items()->create([
                    'service_id' => $item['service_id'],
                    'description' => $isDoctorFee && filled($item['description'] ?? null) ? $item['description'] : $services[$item['service_id']]->name,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'list_price' => $isDoctorFee ? $doctorFee : $services[$item['service_id']]->default_price,
                    'discount_amount' => $item['discount_amount'] ?? 0,
                    'gst_percent' => $item['gst_percent'] ?? 0,
                    'tax_amount' => $calc['lines'][$i]['tax'],
                    'line_total' => $calc['lines'][$i]['total'],
                ]);
            }

            if (to_paise($data['pay_amount'] ?? 0) > 0) {
                $this->payments->collectForInvoice($invoice, [
                    'amount' => $data['pay_amount'],
                    'mode' => $data['pay_mode'],
                    'reference_no' => $data['pay_reference'] ?? null,
                    'payment_date' => $data['invoice_date'],
                ]);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Cancel an invoice. Payments already collected stay on record (the money was received);
     * the balance is zeroed so the invoice drops out of outstanding dues.
     *
     * @throws ValidationException
     */
    public function cancel(Invoice $invoice, string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['reason' => 'This invoice is already cancelled.']);
            }

            if ($locked->hasOpenEmiPlan()) {
                throw ValidationException::withMessages(['reason' => 'This invoice has an open EMI plan. Cancel the EMI plan first.']);
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'balance_amount' => 0,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
