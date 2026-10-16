<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\Invoice;
use App\Models\Patient;

class OutstandingDuesReport extends AbstractReport
{
    public function key(): string
    {
        return 'outstanding-dues';
    }

    public function title(): string
    {
        return 'Outstanding Dues';
    }

    public function description(): string
    {
        return 'Who owes what, patient by patient: unpaid invoice balances plus stand-alone EMI balances, with overdue EMI shown separately. Pick a period to look only at invoices dated (and plans started) within it.';
    }

    public function icon(): string
    {
        return 'bi-hourglass-split';
    }

    public function defaultsToThisMonth(): bool
    {
        return false;
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $invoices = fn () => $f->period(Invoice::query()->where('status', '!=', InvoiceStatus::Cancelled->value)->where('balance_amount', '>', 0), 'invoice_date');
        // Plans tied to an invoice are already inside that invoice's balance, so only stand-alone plans are added.
        $plans = fn () => $f->period(EmiPlan::query()->open()->whereNull('invoice_id')->where('balance_amount', '>', 0), 'start_date');

        $inv = $invoices()->selectRaw('patient_id, COUNT(*) as n, SUM(balance_amount) as bal')->groupBy('patient_id')->get()->keyBy('patient_id');
        $emi = $plans()->selectRaw('patient_id, SUM(balance_amount) as bal')->groupBy('patient_id')->pluck('bal', 'patient_id');

        // overdue EMI per patient (covers every open plan, linked or not; it is part of the balances above)
        $overdue = EmiInstallment::query()->overdue()
            ->join('emi_plans', 'emi_plans.id', '=', 'emi_installments.emi_plan_id')
            ->when($f->hasPeriod(), fn ($q) => $f->period($q, 'emi_plans.start_date'))
            ->selectRaw('emi_plans.patient_id as patient_id, SUM(emi_installments.amount - emi_installments.paid_amount) as due')
            ->groupBy('emi_plans.patient_id')->pluck('due', 'patient_id');

        $ids = $inv->keys()->merge($emi->keys())->unique()->values();
        $patients = Patient::withTrashed()->whereIn('id', $ids)->get(['id', 'uhid', 'name', 'phone'])->keyBy('id');

        $rows = $ids->map(function ($id) use ($inv, $emi, $overdue, $patients) {
            $invBal = $inv[$id]->bal ?? '0.00';
            $emiBal = $emi[$id] ?? '0.00';

            return [
                'patient' => $patients[$id]->name, 'uhid' => $patients[$id]->uhid, 'phone' => $patients[$id]->phone,
                'invoices' => $inv[$id]->n ?? 0, 'invoice_balance' => $invBal, 'emi_balance' => $emiBal,
                'overdue_emi' => $overdue[$id] ?? '0.00', 'total' => from_paise(to_paise($invBal) + to_paise($emiBal)),
            ];
        })->sortByDesc(fn ($r) => to_paise($r['total']))->values();

        $totals = [
            'patient' => 'Total', 'invoices' => $rows->sum('invoices'), 'invoice_balance' => $this->sumMoney($rows->pluck('invoice_balance')),
            'emi_balance' => $this->sumMoney($rows->pluck('emi_balance')), 'overdue_emi' => $this->sumMoney($rows->pluck('overdue_emi')),
            'total' => $this->sumMoney($rows->pluck('total')),
        ];

        [$detail, $detailTotal] = $this->limited($invoices()->with('patient:id,uhid,name')->orderBy('invoice_date')->orderBy('id'), $limit);

        return $this->result('Cancelled invoices excluded', $f, [
            ReportResult::figure('Patients with dues', $rows->count()),
            ReportResult::figure('Total outstanding', $totals['total'], 'money'),
            ReportResult::figure('Invoice balances', $totals['invoice_balance'], 'money'),
            ReportResult::figure('Stand-alone EMI balances', $totals['emi_balance'], 'money'),
            ReportResult::figure('Overdue EMI (included above)', $totals['overdue_emi'], 'money'),
        ], [
            new ReportSection('Patient-wise outstanding', [
                $this->col('patient', 'Patient'), $this->col('uhid', 'UHID'), $this->col('phone', 'Phone'), $this->col('invoices', 'Unpaid invoices', 'int'),
                $this->col('invoice_balance', 'Invoice balance', 'money'), $this->col('emi_balance', 'EMI balance', 'money'),
                $this->col('overdue_emi', 'Of which overdue EMI', 'money'), $this->col('total', 'Total due', 'money'),
            ], ($limit === null ? $rows : $rows->take($limit))->all(), $totals, $rows->count()),
            new ReportSection('Invoices with a balance', [
                $this->col('number', 'Invoice'), $this->col('date', 'Date', 'date'), $this->col('patient', 'Patient'), $this->col('total', 'Total', 'money'),
                $this->col('paid', 'Paid', 'money'), $this->col('balance', 'Balance', 'money'), $this->col('age', 'Age (days)', 'int'),
            ], $detail->map(fn (Invoice $i) => [
                'number' => $i->invoice_number, 'date' => $i->invoice_date, 'patient' => $i->patient->name.' ('.$i->patient->uhid.')',
                'total' => $i->grand_total, 'paid' => $i->paid_amount, 'balance' => $i->balance_amount, 'age' => (int) $i->invoice_date->diffInDays(today()),
            ])->all(), ['number' => 'Total', 'balance' => $totals['invoice_balance']], $detailTotal),
        ]);
    }
}
