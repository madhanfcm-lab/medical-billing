{{-- Loads the bill form's JavaScript. $services and $modes come from the controller. --}}
<script>
window.INVOICE_CFG = {
    services: @json($services),
    modes: @json($modes),
    doctors: @json(collect($doctors)->mapWithKeys(fn ($d) => [$d->id => ['name' => $d->name, 'fee' => (float) $d->consultation_fee]])),
    consultId: {{ \App\Models\Service::doctorConsultation()->id }},
    quickUrl: @json(auth()->user()->can('invoices.create') ? route('services.quick-store') : null),
    oldItems: @json(old('items', [])),
};
</script>
<script src="{{ asset('js/invoice-form.js') }}"></script>
