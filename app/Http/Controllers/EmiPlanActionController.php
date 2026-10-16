<?php

namespace App\Http\Controllers;

use App\Http\Requests\Emi\CancelEmiPlanRequest;
use App\Http\Requests\Emi\CollectEmiPaymentRequest;
use App\Http\Requests\Emi\RescheduleEmiRequest;
use App\Models\EmiPlan;
use App\Services\EmiPaymentService;
use App\Services\EmiService;
use Illuminate\Http\RedirectResponse;

/** Everything you can do to an existing plan: collect, reschedule, cancel, default, reactivate. */
class EmiPlanActionController extends Controller
{
    public function __construct(private readonly EmiService $emi) {}

    public function collect(CollectEmiPaymentRequest $request, EmiPlan $emiPlan, EmiPaymentService $payments): RedirectResponse
    {
        $payment = $payments->collect($emiPlan, $request->validated());
        $done = $emiPlan->refresh()->status->value === 'completed';
        $message = 'Payment of '.money($payment->amount)." recorded (receipt {$payment->receipt_number})".($done ? '. The plan is now fully paid!' : '.');

        // Collected from the patient page: go back there, with the receipt ready to print.
        if ($request->input('return_to') === 'patient') {
            return redirect()->route('patients.show', $emiPlan->patient_id)->with('success', $message)->with('receipt_id', $payment->id)->with('desk', true);
        }

        return redirect()->route('emi-plans.show', $emiPlan)
            ->with('success', 'Payment of '.money($payment->amount)." recorded (receipt {$payment->receipt_number})".($done ? '. The plan is now fully paid!' : '.'))
            ->with('receipt_id', $payment->id);
    }

    public function reschedule(RescheduleEmiRequest $request, EmiPlan $emiPlan): RedirectResponse
    {
        $this->emi->reschedule($emiPlan, $request->validated(), $request->user());

        return redirect()->route('emi-plans.show', $emiPlan)->with('success', 'Remaining balance rescheduled. The previous schedule is kept in the history.');
    }

    public function cancel(CancelEmiPlanRequest $request, EmiPlan $emiPlan): RedirectResponse
    {
        $this->emi->cancel($emiPlan, $request->validated('reason'), $request->user());

        return redirect()->route('emi-plans.show', $emiPlan)->with('success', 'EMI plan cancelled.');
    }

    public function markDefaulted(EmiPlan $emiPlan): RedirectResponse
    {
        $this->authorize('markDefaulted', $emiPlan);
        $this->emi->markDefaulted($emiPlan);

        return redirect()->route('emi-plans.show', $emiPlan)->with('success', 'Plan marked as defaulted.');
    }

    public function reactivate(EmiPlan $emiPlan): RedirectResponse
    {
        $this->authorize('reactivate', $emiPlan);
        $this->emi->reactivate($emiPlan);

        return redirect()->route('emi-plans.show', $emiPlan)->with('success', 'Plan reactivated.');
    }
}
