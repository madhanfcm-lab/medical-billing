<?php

namespace App\Reports;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;

class AttendanceReport extends AbstractReport
{
    /** The day-by-day grid is only drawn for ranges up to this many days (wider ranges get the per-employee summary only). */
    public const GRID_MAX_DAYS = 31;

    public function key(): string
    {
        return 'attendance';
    }

    public function title(): string
    {
        return 'Attendance';
    }

    public function description(): string
    {
        return 'Present / Absent / Half Day / Leave counts for each employee over the period, plus a day-by-day grid for ranges up to 31 days.';
    }

    public function icon(): string
    {
        return 'bi-person-check';
    }

    public function filters(): array
    {
        return ['period', 'employee'];
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $from = $f->from ?? today()->startOfMonth();
        $to = $f->to ?? today();

        $records = Attendance::query()->whereDate('attendance_date', '>=', $from->toDateString())->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($f->employeeId, fn ($q) => $q->where('employee_id', $f->employeeId))->get()->groupBy('employee_id');

        $employees = Employee::withTrashed()
            ->when($f->employeeId, fn ($q) => $q->whereKey($f->employeeId))
            ->where(fn ($q) => $q->where(fn ($a) => $a->active()->whereNull('deleted_at')->joinedBy($to))->orWhereIn('id', $records->keys()))
            ->orderBy('name')->get();

        $zero = collect(AttendanceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => 0])->all();
        $summary = $grid = [];
        $totals = $zero;
        foreach ($employees as $e) {
            $counts = $zero;
            $cells = [];
            foreach ($records->get($e->id, collect()) as $a) {
                $counts[$a->status->value]++;
                $totals[$a->status->value]++;
                $cells['d'.$a->attendance_date->format('Ymd')] = $a->status->code();
            }
            $summary[] = ['code' => $e->employee_code, 'name' => $e->name] + $counts + ['marked' => array_sum($counts)];
            $grid[] = ['name' => $e->name] + $cells + ['p' => $counts['present'], 'a' => $counts['absent'], 'hd' => $counts['half_day'], 'l' => $counts['leave']];
        }

        $sections = [new ReportSection('Summary by employee', [
            $this->col('code', 'Code'), $this->col('name', 'Employee'), $this->col('present', 'Present', 'int'), $this->col('absent', 'Absent', 'int'),
            $this->col('half_day', 'Half day', 'int'), $this->col('leave', 'Leave', 'int'), $this->col('marked', 'Days marked', 'int'),
        ], $summary, ['code' => 'Total'] + $totals + ['marked' => array_sum($totals)])];

        $days = (int) $from->diffInDays($to) + 1;
        if ($days <= self::GRID_MAX_DAYS && $employees->isNotEmpty()) {
            $columns = [$this->col('name', 'Employee')];
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $columns[] = $this->col('d'.$d->format('Ymd'), $d->format('d'));
            }
            foreach (['p' => 'P', 'a' => 'A', 'hd' => 'HD', 'l' => 'L'] as $k => $label) {
                $columns[] = $this->col($k, $label, 'int');
            }
            $sections[] = new ReportSection('Day-by-day', $columns, $grid, ['name' => 'Total', 'p' => $totals['present'], 'a' => $totals['absent'], 'hd' => $totals['half_day'], 'l' => $totals['leave']]);
        }

        return new ReportResult($this->title(), $this->range($from, $to).($f->employeeId ? ' · '.$employees->first()?->name : ''), [
            ReportResult::figure('Employees', $employees->count()),
            ReportResult::figure('Present days', $totals['present']),
            ReportResult::figure('Absent days', $totals['absent']),
            ReportResult::figure('Half days', $totals['half_day']),
            ReportResult::figure('Leave days', $totals['leave']),
        ], $sections);
    }

    private function range(Carbon $from, Carbon $to): string
    {
        return format_date($from).' to '.format_date($to);
    }
}
