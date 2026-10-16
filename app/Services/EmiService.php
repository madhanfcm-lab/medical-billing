<?php

namespace App\Services;

use App\Enums\EmiPlanStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentType;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\EmiReschedule;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EmiService
{
    public function __construct(
        private readonly EmiScheduleGenerator $generator,
        private readonly NumberGenerator $numbers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Create a plan, its full instalment schedule and (optionally) record the down payment.
     * The schedule covers total - down payment.
     *
     * @param  array<string, mixed>  $data  Validated input (see StoreEmiPlanRequest)
     *
     * @throws ValidationException
     */
    public function create(array $data): EmiPlan
    {
        $total = to_paise($data['total_amount']);
        $down = to_paise($data['down_payment'] ?? 0);
        $financed = $total - $down;

        try {
            $schedule = $this->generator->generate(
                from_paise($financed), $data['calc_by'], $data['months'] ?? null, $data['monthly_amount'] ?? null,
                Carbon::parse($data['start_date']), (int) $data['due_day'],
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['months' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($data, $total, $down, $financed, $schedule) {
            $invoice = null;
            if (! empty($data['invoice_id'])) {
                $invoice = Invoice::query()->whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail();
                $this->assertInvoiceCanBeFinanced($invoice, $total);
            }

            $plan = EmiPlan::create([
                'plan_number' => $this->numbers->next('emi', 'EMI'),
                'patient_id' => $data['patient_id'],
                'visit_id' => $data['visit_id'] ?? null,
                'invoice_id' => $invoice?->id,
                'treatment_name' => $data['treatment_name'],
                'total_amount' => from_paise($total),
                'down_payment' => from_paise($down),
                'financed_amount' => from_paise($financed),
                'months' => $schedule['months'],
                'monthly_amount' => $schedule['monthly_amount'],
                'due_day' => $data['due_day'],
                'start_date' => $data['start_date'],
                'first_due_date' => $schedule['first_due_date'],
                'end_date' => $schedule['end_date'],
                'paid_amount' => 0,
                'balance_amount' => from_paise($financed),
                'status' => EmiPlanStatus::Active,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->insertInstallments($plan, $schedule['installments']);

            if ($down > 0) {
                $mode = $this->payments->validateModeAndReference($data['pay_mode'], $data['pay_reference'] ?? null, 'pay_reference');
                $this->payments->issue([
                    'patient_id' => $plan->patient_id,
                    'type' => PaymentType::Emi,
                    'invoice_id' => $invoice?->id,
                    'emi_plan_id' => $plan->id,
                    'amount' => from_paise($down),
                    'mode' => $mode,
                    'reference_no' => $mode->requiresReference() ? $data['pay_reference'] : null,
                    'payment_date' => now()->toDateString(),
                    'balance_after' => from_paise($financed),
                    'notes' => 'Down payment',
                ]);
                if ($invoice) {
                    $this->payments->applyToInvoice($invoice, $down);
                }
            }

            return $plan;
        });
    }

    /**
     * Replace the unpaid part of the schedule (admin). The old schedule is kept as a snapshot.
     * Paid instalments stay as they are; a part-paid instalment is closed at what was paid.
     *
     * @param  array<string, mixed>  $data  calc_by, months|monthly_amount, start_date, due_day, reason
     *
     * @throws ValidationException
     */
    public function reschedule(EmiPlan $plan, array $data, User $by): EmiPlan
    {
        return DB::transaction(function () use ($plan, $data, $by) {
            $locked = EmiPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen() || to_paise($locked->balance_amount) <= 0) {
                throw ValidationException::withMessages(['months' => 'Only a plan with a balance can be rescheduled.']);
            }

            $installments = EmiInstallment::query()->where('emi_plan_id', $locked->id)->orderBy('due_date')->orderBy('installment_no')->lockForUpdate()->get();
            $balance = to_paise($locked->balance_amount);

            try {
                $schedule = $this->generator->generate(
                    from_paise($balance), $data['calc_by'], $data['months'] ?? null, $data['monthly_amount'] ?? null,
                    Carbon::parse($data['start_date']), (int) $data['due_day'], (int) $installments->max('installment_no') + 1,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['months' => $e->getMessage()]);
            }

            EmiReschedule::create([
                'emi_plan_id' => $locked->id,
                'reason' => $data['reason'],
                'balance_at_time' => from_paise($balance),
                'old_schedule' => $installments->map(fn (EmiInstallment $i) => [
                    'no' => $i->installment_no, 'due_date' => $i->due_date->toDateString(),
                    'amount' => $i->amount, 'paid_amount' => $i->paid_amount, 'status' => $i->effective_status->value,
                ])->all(),
                'new_months' => $schedule['months'],
                'new_monthly_amount' => $schedule['monthly_amount'],
                'new_start_date' => $data['start_date'],
                'new_due_day' => $data['due_day'],
                'created_by' => $by->id,
                'created_at' => now(),
            ]);

            foreach ($installments as $i) {
                if (to_paise($i->paid_amount) >= to_paise($i->amount)) {
                    continue; // fully paid: untouched
                }
                if (to_paise($i->paid_amount) > 0) {
                    $i->forceFill(['amount' => $i->paid_amount, 'status' => InstallmentStatus::Paid, 'paid_on' => today()])->save();
                } else {
                    $i->delete(); // nothing was paid against it, so no allocation points at it
                }
            }

            $this->insertInstallments($locked, $schedule['installments']);

            $locked->forceFill([
                'months' => $locked->installments()->count(),
                'monthly_amount' => $schedule['monthly_amount'],
                'due_day' => $data['due_day'],
                'end_date' => $schedule['end_date'],
                'status' => EmiPlanStatus::Active,
                'defaulted_at' => null,
            ])->save();

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function cancel(EmiPlan $plan, string $reason, User $by): EmiPlan
    {
        return $this->transition($plan, fn (EmiPlan $p) => $p->forceFill([
            'status' => EmiPlanStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason,
        ]));
    }

    /** @throws ValidationException */
    public function markDefaulted(EmiPlan $plan): EmiPlan
    {
        return $this->transition($plan, fn (EmiPlan $p) => $p->forceFill(['status' => EmiPlanStatus::Defaulted, 'defaulted_at' => now()]), [EmiPlanStatus::Active]);
    }

    /** @throws ValidationException */
    public function reactivate(EmiPlan $plan): EmiPlan
    {
        return $this->transition($plan, fn (EmiPlan $p) => $p->forceFill(['status' => EmiPlanStatus::Active, 'defaulted_at' => null]), [EmiPlanStatus::Defaulted]);
    }

    /** Daily job: flag unpaid instalments whose due date has passed. Returns how many were updated. */
    public function markOverdue(): int
    {
        return EmiInstallment::query()->overdue()
            ->where('status', '!=', InstallmentStatus::Overdue->value)
            ->update(['status' => InstallmentStatus::Overdue->value]);
    }

    /**
     * @param  list<EmiPlanStatus>|null  $from  allowed current statuses (default: any open status)
     */
    private function transition(EmiPlan $plan, callable $apply, ?array $from = null): EmiPlan
    {
        return DB::transaction(function () use ($plan, $apply, $from) {
            $locked = EmiPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $allowed = $from ?? [EmiPlanStatus::Active, EmiPlanStatus::Defaulted];

            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['plan' => 'This plan is '.$locked->status->label().', so that action is not available.']);
            }
            $apply($locked);
            $locked->save();

            return $locked;
        });
    }

    /** @param  list<array{no: int, due_date: Carbon, amount: string}>  $rows */
    private function insertInstallments(EmiPlan $plan, array $rows): void
    {
        foreach ($rows as $row) {
            EmiInstallment::create([
                'emi_plan_id' => $plan->id,
                'installment_no' => $row['no'],
                'due_date' => $row['due_date'],
                'amount' => $row['amount'],
                'paid_amount' => 0,
                'status' => InstallmentStatus::resolve(to_paise($row['amount']), 0, $row['due_date']),
            ]);
        }
    }

    /** @throws ValidationException */
    private function assertInvoiceCanBeFinanced(Invoice $invoice, int $total): void
    {
        $error = match (true) {
            $invoice->isCancelled() => 'A cancelled invoice cannot be put on an EMI plan.',
            $invoice->hasOpenEmiPlan() => 'This invoice already has an open EMI plan.',
            $total > to_paise($invoice->balance_amount) => 'The plan total cannot exceed the invoice balance of '.money($invoice->balance_amount).'.',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['invoice_id' => $error]);
        }
    }
}
