<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\CollectPaymentRequest;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;

class InvoicePaymentController extends Controller
{
    /** "Collect Due" on an invoice. */
    public function store(CollectPaymentRequest $request, Invoice $invoice, PaymentService $payments): RedirectResponse
    {
        $this->authorize('collect', $invoice);

        $payment = $payments->collectForInvoice($invoice, $request->validated());

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Payment of '.money($payment->amount)." recorded (receipt {$payment->receipt_number}).")
            ->with('receipt_id', $payment->id);
    }
}
