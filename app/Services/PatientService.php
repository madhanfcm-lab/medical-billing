<?php

namespace App\Services;

use App\Enums\EmiPlanStatus;
use App\Enums\InvoiceStatus;
use App\Models\Appointment;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Patient
    {
        return DB::transaction(function () use ($data) {
            $uhid = $this->numbers->next('patient', (string) setting('patient_prefix'));

            $patient = new Patient($data);
            $patient->uhid = $uhid; // not mass-assignable on purpose
            $patient->save();

            return $patient;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Patient $patient, array $data): Patient
    {
        $patient->update($data);

        return $patient;
    }

    /**
     * Everything this patient still owes, for the "Dues" panel and the Receive payment dialog.
     *
     * - invoices: unpaid bills, oldest first. `on_emi` marks bills that are being paid through an open EMI plan.
     * - plans: stand-alone open EMI plans (not tied to a bill) with their next unpaid instalment date.
     * - collectable: what "Receive payment" can take right now (unpaid bills that are not on an EMI plan).
     *
     * @return array{invoices: Collection<int, Invoice>, plans: Collection<int, EmiPlan>, open_plans: Collection<int, EmiPlan>, collectable: string, collectable_count: int}
     */
    public function dues(Patient $patient): array
    {
        $invoices = Invoice::query()->where('patient_id', $patient->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)->where('balance_amount', '>', 0)
            ->withExists(['emiPlans as on_emi' => fn ($q) => $q->open()])
            ->with(['emiPlans' => fn ($q) => $q->open()])
            ->orderBy('invoice_date')->orderBy('id')->get();

        $plans = EmiPlan::query()->where('patient_id', $patient->id)->open()->whereNull('invoice_id')->where('balance_amount', '>', 0)
            ->withMin(['installments as next_due' => fn ($q) => $q->unpaid()], 'due_date')->orderBy('id')->get();

        $collectable = $invoices->reject->on_emi;

        // every open plan (stand-alone or tied to a bill) can take an instalment payment from the Receive payment dialog
        $openPlans = EmiPlan::query()->where('patient_id', $patient->id)->open()->where('balance_amount', '>', 0)
            ->with(['installments' => fn ($q) => $q->unpaid(), 'invoice:id,invoice_number'])->orderBy('id')->get();

        return [
            'invoices' => $invoices,
            'plans' => $plans,
            'open_plans' => $openPlans,
            'collectable' => from_paise($collectable->sum(fn ($i) => to_paise($i->balance_amount))),
            'collectable_count' => $collectable->count(),
        ];
    }

    /**
     * What has already happened for this patient today, so the front desk sees it at a glance.
     *
     * @return array{visits: Collection<int, Visit>, bills: Collection<int, Invoice>, followups: Collection<int, FollowUp>, appointments: Collection<int, Appointment>, paid_today: string}
     */
    public function today(Patient $patient): array
    {
        $today = today()->toDateString();

        return [
            'visits' => Visit::where('patient_id', $patient->id)->whereDate('visit_date', $today)->with('doctor:id,name')->get(),
            'bills' => Invoice::where('patient_id', $patient->id)->whereDate('invoice_date', $today)->where('status', '!=', InvoiceStatus::Cancelled->value)->get(),
            'followups' => FollowUp::where('patient_id', $patient->id)->today()->get(),
            'appointments' => Appointment::where('patient_id', $patient->id)->onDate($today)->whereIn('status', ['scheduled', 'confirmed', 'checked_in'])->with('doctor:id,name')->get(),
            'paid_today' => from_paise((int) Payment::where('patient_id', $patient->id)->whereDate('payment_date', $today)->get()->sum(fn ($p) => to_paise($p->amount))),
        ];
    }

    /**
     * Totals shown at the top of the patient profile (cancelled invoices are excluded).
     * Stand-alone EMI plans are included.
     *
     * @return array{billed: float, paid: float, balance: float}
     */
    public function financials(Patient $patient): array
    {
        $row = $patient->invoices()
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(grand_total),0) as billed, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(balance_amount),0) as balance')
            ->first();

        // EMI plans tied to an invoice are already counted through that invoice; stand-alone plans are added here.
        $plans = $patient->emiPlans()->whereNull('invoice_id')->where('status', '!=', EmiPlanStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(total_amount),0) as billed, COALESCE(SUM(down_payment + paid_amount),0) as paid, COALESCE(SUM(balance_amount),0) as balance')
            ->first();

        return [
            'billed' => (float) $row->billed + (float) $plans->billed,
            'paid' => (float) $row->paid + (float) $plans->paid,
            'balance' => (float) $row->balance + (float) $plans->balance,
        ];
    }
}
