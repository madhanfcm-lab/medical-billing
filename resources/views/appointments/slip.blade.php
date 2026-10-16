<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $appointment->booking_number }}</title>
    @include('partials.doc-style')
</head>
<body>
@unless ($pdf)
    <div class="toolbar"><button onclick="window.print()">Print</button></div>
@endunless
<div class="sheet">
    @include('partials.doc-header', ['docTitle' => 'APPOINTMENT SLIP', 'docMeta' => $appointment->booking_number, 'branch' => $appointment->branch])

    <table class="items" style="margin-bottom:10px">
        <tbody>
            <tr><td class="muted" style="width:28%">Patient</td><td><strong>{{ $appointment->patient->name }}</strong> ({{ $appointment->patient->uhid }})</td></tr>
            <tr><td class="muted">Doctor</td><td>{{ $appointment->doctor->name }}@if ($appointment->doctor->department) · {{ $appointment->doctor->department->name }}@endif</td></tr>
            <tr><td class="muted">Date</td><td style="font-size:15px"><strong>{{ $appointment->appointment_date->format('l, d-m-Y') }}</strong></td></tr>
            <tr><td class="muted">Time</td><td style="font-size:15px"><strong>{{ $appointment->time_label }}</strong> <span class="muted">({{ $appointment->duration_minutes }} minutes)</span></td></tr>
            @if ($appointment->reason)<tr><td class="muted">Reason</td><td>{{ $appointment->reason }}</td></tr>@endif
            <tr><td class="muted">Status</td><td>{{ $appointment->status->label() }}@if ($appointment->confirmed_at) · confirmed {{ format_datetime($appointment->confirmed_at) }}@endif</td></tr>
        </tbody>
    </table>
    <p class="muted" style="font-size:11px">Please arrive 10 minutes early and bring this slip. To change or cancel, call {{ $appointment->branch?->phone ?: setting('hospital_phone') }}.</p>
    <div class="foot">{{ setting('invoice_footer') }}</div>
</div>
</body>
</html>
