<?php

namespace App\Reports;

use App\Enums\PaymentMode;
use App\Models\Employee;
use App\Models\SalaryPayment;
use Illuminate\Database\Eloquent\Builder;

class SalaryReport extends AbstractReport
{
    public function key(): string
    {
        return 'salary';
    }

    public function title(): string
    {
        return 'Salary Payments';
    }

    public function description(): string
    {
        return 'Salary payments recorded in the period (by the date they were paid), in total and per employee.';
    }

    public function icon(): string
    {
        return 'bi-wallet2';
    }

    public function filters(): array
    {
        return ['period', 'employee', 'mode'];
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $base = fn (): Builder => $f->period(SalaryPayment::query(), 'payment_date')
            ->when($f->employeeId, fn ($q) => $q->where('employee_id', $f->employeeId))
            ->when($f->mode, fn ($q) => $q->where('mode', $f->mode));

        $total = $this->sumMoney([$base()->sum('amount')]);
        $count = $base()->count();

        $per = $base()->selectRaw('employee_id, COUNT(*) as n, SUM(amount) as total')->groupBy('employee_id')->orderByDesc('total')->get();
        $emp = Employee::withTrashed()->whereIn('id', $per->pluck('employee_id'))->get(['id', 'name', 'employee_code'])->keyBy('id');

        [$rows, $rowTotal] = $this->limited($base()->with('employee:id,name,employee_code,deleted_at')->orderBy('payment_date')->orderBy('id'), $limit);

        $extra = array_filter([
            $f->employeeId ? 'Employee: '.($emp[$f->employeeId]->name ?? Employee::withTrashed()->find($f->employeeId)?->name) : null,
            $f->mode ? 'Mode: '.PaymentMode::from($f->mode)->label() : null,
        ]);

        return $this->result(implode(' · ', $extra), $f, [
            ReportResult::figure('Total paid', $total, 'money'),
            ReportResult::figure('Payments', $count),
            ReportResult::figure('Employees paid', $per->count()),
        ], [
            new ReportSection('By employee', [$this->col('code', 'Code'), $this->col('name', 'Employee'), $this->col('n', 'Payments', 'int'), $this->col('total', 'Total paid', 'money')],
                $per->map(fn ($r) => ['code' => $emp[$r->employee_id]->employee_code, 'name' => $emp[$r->employee_id]->name, 'n' => $r->n, 'total' => $r->total])->all(),
                ['code' => 'Total', 'n' => $count, 'total' => $total]),
            new ReportSection('Payments', [
                $this->col('date', 'Paid on', 'date'), $this->col('code', 'Code'), $this->col('name', 'Employee'), $this->col('month', 'For month'),
                $this->col('amount', 'Amount', 'money'), $this->col('mode', 'Mode'), $this->col('reference', 'Reference'), $this->col('notes', 'Note'),
            ], $rows->map(fn (SalaryPayment $p) => [
                'date' => $p->payment_date, 'code' => $p->employee->employee_code, 'name' => $p->employee->name, 'month' => $p->month_label,
                'amount' => $p->amount, 'mode' => $p->mode->label(), 'reference' => $p->reference_no, 'notes' => $p->notes,
            ])->all(), ['date' => 'Total', 'amount' => $total], $rowTotal),
        ]);
    }
}
