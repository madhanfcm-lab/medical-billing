<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $result->title }}</title>
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5px; color: #1d2b36; }
        h1 { font-size: 16px; margin: 0; color: #0b3c49; }
        h2 { font-size: 11px; margin: 14px 0 4px; color: #0f8b8d; }
        .muted { color: #6b7c8a; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #eef4f6; text-align: left; padding: 4px 5px; border-bottom: 1px solid #cfdbe1; font-size: 8px; text-transform: uppercase; }
        td { padding: 3.5px 5px; border-bottom: 1px solid #edf1f4; }
        .r { text-align: right; } tfoot td { font-weight: bold; border-top: 1px solid #1d2b36; background: #f7fafb; }
        .fig td { border: 0; padding: 2px 8px 2px 0; } .fig .v { font-weight: bold; font-size: 10px; }
        tr { page-break-inside: avoid; }
        .foot { margin-top: 10px; color: #6b7c8a; font-size: 7.5px; }
    </style>
</head>
<body>
<h1>{{ setting('hospital_name') }}</h1>
<div style="font-size:13px; font-weight:bold; margin-top:2px">{{ $result->title }}</div>
<div class="muted">{{ $result->subtitle }}</div>

@if ($result->summary)
    <table class="fig" style="width:auto; margin-top:8px"><tr>
        @foreach ($result->summary as $f)
            <td><div class="muted">{{ $f['label'] }}</div><div class="v">{{ \App\Reports\ReportFormatter::display($f['value'], $f['type']) }}</div></td>
        @endforeach
    </tr></table>
@endif

@foreach ($result->sections as $section)
    <h2>{{ $section->title }}</h2>
    @if (count($section->rows) === 0)
        <div class="muted">{{ $section->empty }}</div>
    @else
    <table>
        <thead><tr>@foreach ($section->columns as $c)<th @class(['r' => \App\Reports\ReportFormatter::isNumeric($c['type'])])>{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($section->rows as $row)
            <tr>@foreach ($section->columns as $c)<td @class(['r' => \App\Reports\ReportFormatter::isNumeric($c['type'])])>{{ \App\Reports\ReportFormatter::display($row[$c['key']] ?? null, $c['type']) }}</td>@endforeach</tr>
        @endforeach
        </tbody>
        @if ($section->totals !== null)
        <tfoot><tr>@foreach ($section->columns as $c)
            <td @class(['r' => \App\Reports\ReportFormatter::isNumeric($c['type'])])>{{ array_key_exists($c['key'], $section->totals) ? \App\Reports\ReportFormatter::display($section->totals[$c['key']], $c['type'] === 'date' ? 'text' : $c['type']) : '' }}</td>
        @endforeach</tr></tfoot>
        @endif
    </table>
    @endif
@endforeach

<div class="foot">Generated {{ now()->format('d-m-Y h:i A') }} by {{ $printedBy }}</div>
</body>
</html>
