<a href="{{ route('invoices.show', $invoice) }}" class="btn btn-sm btn-outline-secondary" title="Open"><i class="bi bi-eye"></i></a>
<a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print"><i class="bi bi-printer"></i></a>
<a href="{{ route('invoices.pdf', $invoice) }}" class="btn btn-sm btn-outline-secondary" title="Download PDF"><i class="bi bi-file-earmark-pdf"></i></a>
