<a href="{{ $link }}" target="_blank" rel="noopener" class="btn btn-sm btn-success" title="Send WhatsApp reminder to {{ $installment->plan->patient->phone }}"><i class="bi bi-whatsapp"></i></a>
<a href="{{ route('emi-plans.show', $installment->emi_plan_id) }}" class="btn btn-sm btn-outline-primary" title="Open plan / collect"><i class="bi bi-cash-coin"></i></a>
