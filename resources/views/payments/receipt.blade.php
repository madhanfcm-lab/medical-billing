<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $payment->receipt_number }}</title>
    @include('partials.doc-style')
</head>
<body>
@unless ($pdf)
    <div class="toolbar"><button onclick="window.print()">Print</button> <a href="{{ route('payments.receipt.pdf', $payment) }}">Download PDF</a></div>
@endunless
<div class="sheet">
    @include('partials.doc-header', ['branch' => $payment->branch, 'docTitle' => 'PAYMENT RECEIPT', 'docMeta' => $payment->receipt_number.' · '.format_date($payment->payment_date)])

    <table class="items" style="margin-bottom:10px">
        <tbody>
            <tr><td class="muted" style="width:28%">Received from</td><td><strong>{{ $payment->patient->name }}</strong> ({{ $payment->patient->uhid }})</td></tr>
            <tr><td class="muted">Towards</td><td>
                @if ($payment->emiPlan)
                    EMI plan {{ $payment->emiPlan->plan_number }} — {{ $payment->emiPlan->treatment_name }}
                    @if ($payment->allocations->isNotEmpty())
                        <div class="muted">Instalment {{ $payment->allocations->map(fn ($a) => '#'.$a->installment->installment_no.' ('.money($a->amount).')')->implode(', ') }}</div>
                    @elseif ($payment->notes === 'Down payment')
                        <div class="muted">Down payment</div>
                    @endif
                @elseif ($payment->invoice) Invoice {{ $payment->invoice->invoice_number }} @endif
            </td></tr>
            <tr><td class="muted">Payment mode</td><td>{{ $payment->mode->label() }} @if ($payment->reference_no) · Ref: {{ $payment->reference_no }} @endif</td></tr>
            <tr><td class="muted">Amount received</td><td style="font-size:15px"><strong>{{ money($payment->amount) }}</strong></td></tr>
            <tr><td class="muted">In words</td><td>{{ \App\Support\AmountInWords::rupees($payment->amount) }}</td></tr>
            <tr><td class="muted">Balance after this payment</td><td>{{ $payment->balance_after !== null ? money($payment->balance_after) : '—' }}</td></tr>
            @if ($payment->notes)<tr><td class="muted">Note</td><td>{{ $payment->notes }}</td></tr>@endif
        </tbody>
    </table>
    <table style="margin-top:24px"><tr>
        <td class="muted">Received by: {{ $payment->creator?->name ?? '—' }}</td>
        <td class="r muted">Authorised signature</td>
    </tr></table>
    <div class="foot">{{ setting('invoice_footer') }}</div>
</div>
</body>
</html>
