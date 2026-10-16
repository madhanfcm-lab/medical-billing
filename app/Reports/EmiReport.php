<?php

namespace App\Reports;

use App\Enums\EmiPlanStatus;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use Illuminate\Database\Eloquent\Builder;

class EmiReport extends AbstractReport
{
    public function key(): string
    {
        return 'emi';
    }

    public function title(): string
    {
        return 'EMI Plans';
    }

    public function description(): string
    {
        return 'Every EMI plan started in the period: active, completed, defaulted, cancelled, and which of the open ones have overdue instalments.';
    }

    public function icon(): string
    {
        return 'bi-calendar2-check';
    }

    public function filters(): array
    {
        return ['period', 'status'];
    }

    public function statusOptions(): array
    {
        return collect(EmiPlanStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all() + ['overdue' => 'Overdue (open, with overdue instalments)'];
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $overdueInstalments = fn ($i) => $i->unpaid()->whereDate('due_date', '<', today()->toDateString());

        $base = fn (): Builder => $f->period(EmiPlan::query(), 'start_date')
            ->when($f->status === 'overdue', fn ($q) => $q->open()->whereHas('installments', $overdueInstalments))
            ->when($f->status && $f->status !== 'overdue', fn ($q) => $q->where('status', $f->status));

        $overdueSub = EmiInstallment::query()->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')
            ->whereColumn('emi_installments.emi_plan_id', 'emi_plans.id')
            ->whereColumn('emi_installments.paid_amount', '<', 'emi_installments.amount')
            ->whereDate('emi_installments.due_date', '<', today()->toDateString());

        $t = $base()->selectRaw('COUNT(*) as n, COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(down_payment),0) as down, COALESCE(SUM(paid_amount),0) as paid,
            COALESCE(SUM(CASE WHEN status IN (?, ?) THEN balance_amount ELSE 0 END),0) as bal', ['active', 'defaulted'])->first();
        $overdueTotal = EmiInstallment::query()->overdue()
            ->whereIn('emi_plan_id', $base()->select('emi_plans.id'))
            ->selectRaw('COALESCE(SUM(amount - paid_amount),0) as s')->value('s');

        $byStatus = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $overduePlans = $base()->open()->whereHas('installments', $overdueInstalments)->count();

        [$rows, $rowTotal] = $this->limited(
            $base()->with('patient:id,uhid,name,phone')->select('emi_plans.*')->selectSub($overdueSub, 'overdue_amount')->orderBy('start_date')->orderBy('id'),
            $limit,
        );

        $summary = [
            ReportResult::figure('Plans', $t->n),
            ReportResult::figure('Total value', $t->total, 'money'),
            ReportResult::figure('Received so far', from_paise(to_paise($t->down) + to_paise($t->paid)), 'money'),
            ReportResult::figure('Still to collect', $t->bal, 'money'),
            ReportResult::figure('Overdue amount', $overdueTotal, 'money'),
        ];
        foreach (EmiPlanStatus::cases() as $s) {
            $summary[] = ReportResult::figure($s->label().' plans', $byStatus[$s->value] ?? 0);
        }
        $summary[] = ReportResult::figure('Plans with overdue instalments', $overduePlans);

        return $this->result($f->status ? 'Status: '.($this->statusOptions()[$f->status] ?? $f->status) : '', $f, $summary, [new ReportSection('EMI plans', [
            $this->col('plan', 'Plan'), $this->col('patient', 'Patient'), $this->col('phone', 'Phone'), $this->col('treatment', 'Treatment'),
            $this->col('total', 'Total', 'money'), $this->col('down', 'Down payment', 'money'), $this->col('paid', 'Paid (instalments)', 'money'),
            $this->col('balance', 'Balance', 'money'), $this->col('overdue', 'Overdue', 'money'),
            $this->col('start', 'Started', 'date'), $this->col('end', 'Ends', 'date'), $this->col('status', 'Status'),
        ], $rows->map(fn (EmiPlan $p) => [
            'plan' => $p->plan_number, 'patient' => $p->patient->name.' ('.$p->patient->uhid.')', 'phone' => $p->patient->phone, 'treatment' => $p->treatment_name,
            'total' => $p->total_amount, 'down' => $p->down_payment, 'paid' => $p->paid_amount, 'balance' => $p->balance_amount,
            'overdue' => $p->status->isOpen() ? $p->overdue_amount : '0.00', 'start' => $p->start_date, 'end' => $p->end_date, 'status' => $p->status->label(),
        ])->all(), [
            'plan' => 'Total', 'total' => $t->total, 'down' => $t->down, 'paid' => $t->paid, 'balance' => $t->bal, 'overdue' => $overdueTotal,
        ], $rowTotal)]);
    }
}
