<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    @include('partials.doc-style')
</head>
<body>
@unless ($pdf)
    <div class="toolbar"><button onclick="window.print()">Print</button> <a href="{{ route('invoices.pdf', $invoice) }}">Download PDF</a></div>
@endunless
<div class="sheet">
    @include('partials.doc-header', ['branch' => $invoice->branch, 'docTitle' => 'INVOICE', 'docMeta' => $invoice->invoice_number.' · '.format_date($invoice->invoice_date)])

    <table style="margin-bottom:12px"><tr>
        <td class="box" style="width:50%">
            <div class="muted">Billed to</div>
            <strong>{{ $invoice->patient->name }}</strong><br>
            UHID: {{ $invoice->patient->uhid }} · {{ $invoice->patient->age_label }}, {{ $invoice->patient->gender->label() }}<br>
            Phone: {{ $invoice->patient->phone }}
            @if ($invoice->patient->address)<br>{{ $invoice->patient->address }}@endif
        </td>
        <td style="width:3%"></td>
        <td class="box" style="width:47%">
            <div class="muted">Details</div>
            Doctor: {{ $invoice->doctor?->name ?? '—' }}<br>
            Invoice date: {{ format_date($invoice->invoice_date) }}<br>
            Status: <strong>{{ $invoice->status->label() }}</strong>
        </td>
    </tr></table>

    <table class="items">
        <thead><tr><th style="width:5%">#</th><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Disc.</th><th class="r">GST</th><th class="r">Amount</th></tr></thead>
        <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td><td>{{ $item->description }}</td><td class="r">{{ $item->quantity }}</td>
                <td class="r">{{ money($item->unit_price) }}</td><td class="r">{{ money($item->discount_amount) }}</td>
                <td class="r">{{ rtrim(rtrim($item->gst_percent, '0'), '.') }}%</td><td class="r">{{ money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:10px"><tr>
        <td style="width:52%; vertical-align:top">
            <div class="muted">Amount in words</div>
            <div>{{ \App\Support\AmountInWords::rupees($invoice->grand_total) }}</div>
            @if ($invoice->notes)<div class="muted" style="margin-top:8px">Notes</div><div>{{ $invoice->notes }}</div>@endif
            <div style="margin-top:14px">
                @if ($invoice->isCancelled())<span class="stamp">CANCELLED</span>
                @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)<span class="stamp paid">PAID</span>
                @else<span class="stamp due">DUE {{ money($invoice->balance_amount) }}</span>@endif
            </div>
        </td>
        <td style="width:48%; vertical-align:top">
            <table class="totals">
                <tr><td>Subtotal</td><td class="r">{{ money($invoice->subtotal) }}</td></tr>
                <tr><td>Discount @if ($invoice->discount_type === 'percent')({{ rtrim(rtrim($invoice->discount_value, '0'), '.') }}%)@endif</td><td class="r">− {{ money($invoice->discount_amount) }}</td></tr>
                <tr><td>GST</td><td class="r">{{ money($invoice->tax_amount) }}</td></tr>
                <tr class="grand"><td>Grand total</td><td class="r">{{ money($invoice->grand_total) }}</td></tr>
                <tr><td>Paid</td><td class="r">{{ money($invoice->paid_amount) }}</td></tr>
                <tr><td><strong>Balance</strong></td><td class="r"><strong>{{ money($invoice->balance_amount) }}</strong></td></tr>
            </table>
        </td>
    </tr></table>

    @if ($invoice->payments->isNotEmpty())
        <div class="muted" style="margin-top:12px">Payments received</div>
        <table class="items">
            <thead><tr><th>Receipt</th><th>Date</th><th>Mode</th><th>Reference</th><th class="r">Amount</th></tr></thead>
            <tbody>
            @foreach ($invoice->payments as $p)
                <tr><td>{{ $p->receipt_number }}</td><td>{{ format_date($p->payment_date) }}</td><td>{{ $p->mode->label() }}</td><td>{{ $p->reference_no ?? '—' }}</td><td class="r">{{ money($p->amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="foot">{{ setting('invoice_footer') }}</div>
</div>
</body>
</html>
