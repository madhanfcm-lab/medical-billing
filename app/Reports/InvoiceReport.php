<?php

namespace App\Reports;

use App\Enums\InvoiceStatus;
use App\Models\Doctor;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

class InvoiceReport extends AbstractReport
{
    public function key(): string
    {
        return 'invoices';
    }

    public function title(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Every invoice in the period with billed amount, discount, tax, paid and balance. Cancelled invoices are listed but left out of the totals.';
    }

    public function icon(): string
    {
        return 'bi-receipt';
    }

    public function filters(): array
    {
        return ['period', 'doctor', 'status'];
    }

    public function statusOptions(): array
    {
        return collect(InvoiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $base = fn (): Builder => $f->period(Invoice::query(), 'invoice_date')
            ->when($f->doctorId, fn ($q) => $q->where('doctor_id', $f->doctorId))
            ->when($f->status, fn ($q) => $q->where('status', $f->status));

        $live = $base()->where('status', '!=', InvoiceStatus::Cancelled->value);
        $t = (clone $live)->selectRaw('COUNT(*) as n, COALESCE(SUM(subtotal),0) as sub, COALESCE(SUM(discount_amount),0) as disc, COALESCE(SUM(tax_amount),0) as tax,
            COALESCE(SUM(grand_total),0) as billed, COALESCE(SUM(paid_amount),0) as paid, COALESCE(SUM(balance_amount),0) as bal')->first();
        $cancelled = $base()->where('status', InvoiceStatus::Cancelled->value)->count();

        [$rows, $rowTotal] = $this->limited($base()->with(['patient:id,uhid,name', 'doctor:id,name'])->orderBy('invoice_date')->orderBy('id'), $limit);

        $extra = array_filter([
            $f->doctorId ? 'Doctor: '.(Doctor::withTrashed()->find($f->doctorId)?->name) : null,
            $f->status ? 'Status: '.InvoiceStatus::from($f->status)->label() : null,
        ]);

        return $this->result(implode(' · ', $extra), $f, [
            ReportResult::figure('Invoices', $t->n),
            ReportResult::figure('Billed', $t->billed, 'money'),
            ReportResult::figure('Discount given', $t->disc, 'money'),
            ReportResult::figure('Collected', $t->paid, 'money'),
            ReportResult::figure('Balance due', $t->bal, 'money'),
            ReportResult::figure('Cancelled (excluded)', $cancelled),
        ], [new ReportSection('Invoices', [
            $this->col('number', 'Invoice'), $this->col('date', 'Date', 'date'), $this->col('patient', 'Patient'), $this->col('doctor', 'Doctor'),
            $this->col('subtotal', 'Subtotal', 'money'), $this->col('discount', 'Discount', 'money'), $this->col('tax', 'GST', 'money'),
            $this->col('total', 'Total', 'money'), $this->col('paid', 'Paid', 'money'), $this->col('balance', 'Balance', 'money'), $this->col('status', 'Status'),
        ], $rows->map(fn (Invoice $i) => [
            'number' => $i->invoice_number, 'date' => $i->invoice_date, 'patient' => $i->patient->name.' ('.$i->patient->uhid.')', 'doctor' => $i->doctor?->name ?? '—',
            'subtotal' => $i->subtotal, 'discount' => $i->discount_amount, 'tax' => $i->tax_amount, 'total' => $i->grand_total,
            'paid' => $i->paid_amount, 'balance' => $i->balance_amount, 'status' => $i->status->label(),
        ])->all(), [
            'number' => 'Total (excl. cancelled)', 'subtotal' => $t->sub, 'discount' => $t->disc, 'tax' => $t->tax, 'total' => $t->billed, 'paid' => $t->paid, 'balance' => $t->bal,
        ], $rowTotal)]);
    }
}
