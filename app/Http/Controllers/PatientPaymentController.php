<?php

namespace App\Http\Controllers;

use App\Http\Requests\Billing\ReceivePatientPaymentRequest;
use App\Models\Patient;
use App\Services\PatientPaymentService;
use Illuminate\Http\RedirectResponse;

class PatientPaymentController extends Controller
{
    /** "Receive payment" on the patient page: one amount, applied to the oldest unpaid bills first. */
    public function store(ReceivePatientPaymentRequest $request, Patient $patient, PatientPaymentService $service): RedirectResponse
    {
        $result = $service->collect($patient, $request->validated());
        $payments = $result['payments'];
        $total = from_paise($payments->sum(fn ($p) => to_paise($p->amount)));
        $bills = $payments->count();

        return redirect()->route('patients.show', $patient)
            ->with('success', 'Received '.money($total).' against '.$bills.($bills === 1 ? ' bill' : ' bills').'.')
            ->with('batch_ref', $result['batch_ref'])
            ->with('receipt_count', $bills)
            ->with('receipt_id', $payments->first()->id);
    }
}
