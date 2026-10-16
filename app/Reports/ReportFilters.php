<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final class ReportFilters
{
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $doctorId = null,
        public readonly ?int $employeeId = null,
        public readonly ?string $status = null,
        public readonly ?string $mode = null,
    ) {}

    /** Build from request input, keeping only the filters this report actually uses. */
    public static function for(Report $report, array $input): self
    {
        $uses = $report->filters();
        $from = $to = null;

        if (in_array('period', $uses, true)) {
            $from = filled($input['from'] ?? null) ? Carbon::parse($input['from'])->startOfDay() : null;
            $to = filled($input['to'] ?? null) ? Carbon::parse($input['to'])->startOfDay() : null;
            if ($from === null && $to === null && $report->defaultsToThisMonth()) {
                [$from, $to] = [today()->startOfMonth(), today()];
            }
        }
        $pick = fn (string $key, string $use) => in_array($use, $uses, true) && filled($input[$key] ?? null) ? $input[$key] : null;

        return new self(
            $from, $to,
            doctorId: ($d = $pick('doctor_id', 'doctor')) ? (int) $d : null,
            employeeId: ($e = $pick('employee_id', 'employee')) ? (int) $e : null,
            status: $pick('status', 'status'),
            mode: $pick('mode', 'mode'),
        );
    }

    public function hasPeriod(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /** Restrict a date column to the chosen period (either bound may be open). */
    public function period(Builder|QueryBuilder $query, string $column): Builder|QueryBuilder
    {
        return $query
            ->when($this->from, fn ($q) => $q->whereDate($column, '>=', $this->from->toDateString()))
            ->when($this->to, fn ($q) => $q->whereDate($column, '<=', $this->to->toDateString()));
    }

    public function periodLabel(): string
    {
        return match (true) {
            $this->from && $this->to => format_date($this->from).' to '.format_date($this->to),
            (bool) $this->from => 'From '.format_date($this->from),
            (bool) $this->to => 'Up to '.format_date($this->to),
            default => 'All dates',
        };
    }

    /** Number of calendar days in the period (null when open-ended). */
    public function days(): ?int
    {
        return $this->from && $this->to ? (int) $this->from->diffInDays($this->to) + 1 : null;
    }
}
