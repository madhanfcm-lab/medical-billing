<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Visit;

class DoctorRevenueReport extends AbstractReport
{
    public function key(): string
    {
        return 'doctor-revenue';
    }

    public function title(): string
    {
        return 'Doctor-wise Revenue';
    }

    public function description(): string
    {
        return 'Billing per doctor for invoices dated in the period: billed, discount, collected so far and still unpaid, plus visits seen. Cancelled invoices and stand-alone EMI plans (which have no doctor) are not included.';
    }

    public function icon(): string
    {
        return 'bi-person-badge';
    }

    public function filters(): array
    {
        return ['period', 'doctor'];
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $inv = $f->period(Invoice::query()->where('status', '!=', InvoiceStatus::Cancelled->value), 'invoice_date')
            ->when($f->doctorId, fn ($q) => $q->where('doctor_id', $f->doctorId))
            ->selectRaw('doctor_id, COUNT(*) as n, COALESCE(SUM(discount_amount),0) as disc, COALESCE(SUM(grand_total),0) as billed,
                COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(balance_amount),0) as bal')
            ->groupBy('doctor_id')->get()->keyBy(fn ($r) => $r->doctor_id ?? 0);

        $visits = $f->period(Visit::query(), 'visit_date')
            ->when($f->doctorId, fn ($q) => $q->where('doctor_id', $f->doctorId))
            ->selectRaw('doctor_id, COUNT(*) as n')->groupBy('doctor_id')->pluck('n', 'doctor_id');

        $ids = $inv->keys()->merge($visits->keys())->unique()->values();
        $doctors = Doctor::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'specialization'])->keyBy('id');

        $rows = $ids->map(fn ($id) => [
            'doctor' => $id === 0 ? 'No doctor assigned' : $doctors[$id]->name,
            'specialization' => $id === 0 ? '—' : ($doctors[$id]->specialization ?? '—'),
            'visits' => $visits[$id] ?? 0, 'invoices' => $inv[$id]->n ?? 0,
            'billed' => $inv[$id]->billed ?? '0.00', 'discount' => $inv[$id]->disc ?? '0.00',
            'collected' => $inv[$id]->paid ?? '0.00', 'balance' => $inv[$id]->bal ?? '0.00',
        ])->sortByDesc(fn ($r) => to_paise($r['billed']))->values();

        $totals = [
            'doctor' => 'Total', 'visits' => $rows->sum('visits'), 'invoices' => $rows->sum('invoices'), 'billed' => $this->sumMoney($rows->pluck('billed')),
            'discount' => $this->sumMoney($rows->pluck('discount')), 'collected' => $this->sumMoney($rows->pluck('collected')), 'balance' => $this->sumMoney($rows->pluck('balance')),
        ];

        return $this->result($f->doctorId ? 'Doctor: '.(Doctor::withTrashed()->find($f->doctorId)?->name) : '', $f, [
            ReportResult::figure('Billed', $totals['billed'], 'money'),
            ReportResult::figure('Collected so far', $totals['collected'], 'money'),
            ReportResult::figure('Unpaid', $totals['balance'], 'money'),
            ReportResult::figure('Discount given', $totals['discount'], 'money'),
            ReportResult::figure('Visits', $totals['visits']),
        ], [new ReportSection('Revenue by doctor', [
            $this->col('doctor', 'Doctor'), $this->col('specialization', 'Specialization'), $this->col('visits', 'Visits', 'int'), $this->col('invoices', 'Invoices', 'int'),
            $this->col('billed', 'Billed', 'money'), $this->col('discount', 'Discount', 'money'), $this->col('collected', 'Collected', 'money'), $this->col('balance', 'Unpaid', 'money'),
        ], $rows->all(), $totals)]);
    }
}
