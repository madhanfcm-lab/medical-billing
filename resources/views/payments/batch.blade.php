@php
    $first = $payments->first();
    $total = from_paise($payments->sum(fn ($p) => to_paise($p->amount)));
    $left = $payments->sum(fn ($p) => to_paise($p->balance_after ?? 0));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $first->receipt_number }}</title>
    @include('partials.doc-style')
</head>
<body>
@unless ($pdf)
    <div class="toolbar"><button onclick="window.print()">Print</button> <a href="{{ route('payments.batch.pdf', $ref) }}">Download PDF</a></div>
@endunless
<div class="sheet">
    @include('partials.doc-header', ['branch' => $first->branch, 'docTitle' => 'PAYMENT RECEIPT', 'docMeta' => $first->receipt_number.($payments->count() > 1 ? ' to '.$payments->last()->receipt_number : '').' · '.format_date($first->payment_date)])

    <table class="items" style="margin-bottom:8px">
        <tbody>
            <tr><td class="muted" style="width:28%">Received from</td><td><strong>{{ $first->patient->name }}</strong> ({{ $first->patient->uhid }})</td></tr>
            <tr><td class="muted">Payment mode</td><td>{{ $first->mode->label() }} @if ($first->reference_no) · Ref: {{ $first->reference_no }} @endif</td></tr>
            @if ($first->notes)<tr><td class="muted">Note</td><td>{{ $first->notes }}</td></tr>@endif
        </tbody>
    </table>

    <table class="items">
        <thead><tr><th>Receipt no.</th><th>Towards bill</th><th>Bill date</th><th class="r">Amount received</th><th class="r">Left on this bill</th></tr></thead>
        <tbody>
        @foreach ($payments as $p)
            <tr>
                <td>{{ $p->receipt_number }}</td>
                <td>{{ $p->invoice?->invoice_number ?? '—' }}</td>
                <td>{{ $p->invoice ? format_date($p->invoice->invoice_date) : '—' }}</td>
                <td class="r">{{ money($p->amount) }}</td>
                <td class="r">{{ money($p->balance_after) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr class="grand"><td colspan="3" class="r">Total received</td><td class="r">{{ money($total) }}</td><td class="r">{{ money(from_paise($left)) }}</td></tr></tfoot>
    </table>

    <table style="margin-top:10px"><tr>
        <td><span class="muted">In words</span><br>{{ \App\Support\AmountInWords::rupees($total) }}</td>
    </tr></table>
    <table style="margin-top:22px"><tr>
        <td class="muted">Received by: {{ $first->creator?->name ?? '—' }}</td>
        <td class="r muted">Authorised signature</td>
    </tr></table>
    <div class="foot">{{ setting('invoice_footer') }}</div>
</div>
</body>
</html>
