<style>
    @page { margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', 'Inter', Arial, sans-serif; font-size: 11px; color: #1d2b36; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .hdr td { vertical-align: top; }
    .clinic { font-size: 17px; font-weight: bold; color: #0b3c49; }
    .muted { color: #6b7c8a; }
    .title { text-align: right; font-size: 20px; font-weight: bold; color: #0f8b8d; letter-spacing: 1px; }
    .box { border: 1px solid #d9e2e8; padding: 8px 10px; }
    .items th { background: #eef4f6; text-align: left; padding: 6px 7px; border-bottom: 1px solid #cfdbe1; font-size: 10px; text-transform: uppercase; }
    .items td { padding: 6px 7px; border-bottom: 1px solid #edf1f4; }
    .items th.r, .r { text-align: right; } .c { text-align: center; }
    .totals td { padding: 3px 7px; }
    .grand td { font-size: 13px; font-weight: bold; border-top: 1px solid #1d2b36; padding-top: 6px; }
    .stamp { display: inline-block; border: 2px solid #6c757d; color: #6c757d; padding: 3px 12px; font-weight: bold; font-size: 15px; transform: rotate(-6deg); }
    .paid { border-color: #198754; color: #198754; } .due { border-color: #dc3545; color: #dc3545; }
    .foot { margin-top: 18px; border-top: 1px solid #d9e2e8; padding-top: 8px; text-align: center; color: #6b7c8a; }
    .toolbar { padding: 10px 16px; background: #0b3c49; text-align: right; }
    .toolbar button, .toolbar a { background: #0f8b8d; color: #fff; border: 0; padding: 7px 16px; border-radius: 6px; font-size: 13px; text-decoration: none; cursor: pointer; }
    @media print { .toolbar { display: none; } }
    @if (! $pdf)
    @media screen { .sheet { max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 2px 14px rgba(0,0,0,.15); } body { background: #e9eef1; } }
    @endif
</style>
