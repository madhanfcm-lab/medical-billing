<?php

namespace App\Reports;

use App\Enums\PaymentMode;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DailyCollectionReport extends AbstractReport
{
    public function key(): string
    {
        return 'daily-collection';
    }

    public function title(): string
    {
        return 'Daily Collection';
    }

    public function description(): string
    {
        return 'Money received by day, payment mode and the staff member who collected it. Includes invoice payments, EMI payments and down payments.';
    }

    public function icon(): string
    {
        return 'bi-cash-coin';
    }

    public function filters(): array
    {
        return ['period', 'mode'];
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $base = fn (): Builder => $f->period(Payment::query(), 'payment_date')->when($f->mode, fn ($q) => $q->where('mode', $f->mode));

        $total = $this->sumMoney([$base()->sum('amount')]);
        $count = $base()->count();

        // by mode
        $byMode = $base()->selectRaw('mode, COUNT(*) as n, SUM(amount) as total')->groupBy('mode')->orderByDesc('total')->get()
            ->map(fn ($r) => ['mode' => PaymentMode::from($r->mode instanceof PaymentMode ? $r->mode->value : $r->mode)->label(), 'n' => $r->n, 'total' => $r->total])->all();

        // by staff
        $staffRows = $base()->selectRaw('created_by, COUNT(*) as n, SUM(amount) as total')->groupBy('created_by')->orderByDesc('total')->get();
        $names = User::withTrashed()->whereIn('id', $staffRows->pluck('created_by')->filter())->pluck('name', 'id');
        $byStaff = $staffRows->map(fn ($r) => ['staff' => $names[$r->created_by] ?? 'System', 'n' => $r->n, 'total' => $r->total])->all();

        // by day x mode
        $days = [];
        foreach ($base()->selectRaw('payment_date as d, mode, COUNT(*) as n, SUM(amount) as total')->groupBy('payment_date', 'mode')->get() as $r) {
            $mode = $r->mode instanceof PaymentMode ? $r->mode->value : $r->mode;
            $day = &$days[substr((string) $r->d, 0, 10)];
            $day ??= ['date' => substr((string) $r->d, 0, 10), 'n' => 0, 'total_paise' => 0];
            $day['n'] += $r->n;
            $day[$mode] = $r->total;
            $day['total_paise'] += to_paise($r->total);
            unset($day);
        }
        ksort($days);
        $zeroModes = collect(PaymentMode::cases())->mapWithKeys(fn ($m) => [$m->value => '0.00'])->all();
        $dayRows = array_map(fn ($d) => $d + $zeroModes + ['total' => from_paise($d['total_paise'])], array_values($days));

        $dayColumns = [$this->col('date', 'Date', 'date'), $this->col('n', 'Receipts', 'int')];
        foreach (PaymentMode::cases() as $m) {
            $dayColumns[] = $this->col($m->value, $m->label(), 'money');
        }
        $dayColumns[] = $this->col('total', 'Total', 'money');

        $dayTotals = ['date' => 'Total', 'n' => $count, 'total' => $total];
        foreach (PaymentMode::cases() as $m) {
            $dayTotals[$m->value] = $this->sumMoney(array_column($dayRows, $m->value));
        }

        // receipts
        [$receipts, $receiptTotal] = $this->limited(
            $base()->with(['patient:id,uhid,name', 'invoice:id,invoice_number', 'emiPlan:id,plan_number', 'creator:id,name'])->orderBy('payment_date')->orderBy('id'),
            $limit,
        );

        return $this->result($f->mode ? 'Mode: '.PaymentMode::from($f->mode)->label() : '', $f, [
            ReportResult::figure('Total collected', $total, 'money'),
            ReportResult::figure('Receipts', $count),
            ReportResult::figure('Days with collections', count($dayRows)),
        ], [
            new ReportSection('By day', $dayColumns, $dayRows, $dayTotals),
            new ReportSection('By payment mode', [$this->col('mode', 'Mode'), $this->col('n', 'Receipts', 'int'), $this->col('total', 'Amount', 'money')],
                $byMode, ['mode' => 'Total', 'n' => $count, 'total' => $total]),
            new ReportSection('By staff (collected by)', [$this->col('staff', 'Staff'), $this->col('n', 'Receipts', 'int'), $this->col('total', 'Amount', 'money')],
                $byStaff, ['staff' => 'Total', 'n' => $count, 'total' => $total]),
            new ReportSection('Receipts', [
                $this->col('receipt', 'Receipt'), $this->col('date', 'Date', 'date'), $this->col('patient', 'Patient'), $this->col('against', 'Against'),
                $this->col('mode', 'Mode'), $this->col('reference', 'Reference'), $this->col('amount', 'Amount', 'money'), $this->col('by', 'Collected by'),
            ], $receipts->map(fn (Payment $p) => [
                'receipt' => $p->receipt_number, 'date' => $p->payment_date, 'patient' => $p->patient->name.' ('.$p->patient->uhid.')',
                'against' => $p->emiPlan?->plan_number ?? $p->invoice?->invoice_number ?? '—', 'mode' => $p->mode->label(),
                'reference' => $p->reference_no, 'amount' => $p->amount, 'by' => $p->creator?->name ?? 'System',
            ])->all(), ['receipt' => 'Total', 'amount' => $total], $receiptTotal),
        ]);
    }
}
