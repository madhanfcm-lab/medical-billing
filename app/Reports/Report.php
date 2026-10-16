<?php

namespace App\Reports;

interface Report
{
    /** URL slug, e.g. "daily-collection". */
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /** Bootstrap Icons class. */
    public function icon(): string;

    /** Which filter controls the report uses: period, doctor, employee, status, mode. @return list<string> */
    public function filters(): array;

    /** True: the period defaults to this month. False: the report covers all dates unless one is chosen. */
    public function defaultsToThisMonth(): bool;

    /** Options for the "status" filter, when used. @return array<string, string> */
    public function statusOptions(): array;

    /**
     * @param  int|null  $limit  max rows per detail section (the on-screen view); null = everything (exports)
     */
    public function run(ReportFilters $filters, ?int $limit = null): ReportResult;
}
