<?php

namespace App\Reports;

/** The list of reports. To add one: write a class extending AbstractReport and list it here. */
final class ReportRegistry
{
    private const REPORTS = [
        DailyCollectionReport::class,
        InvoiceReport::class,
        OutstandingDuesReport::class,
        EmiReport::class,
        FollowUpReport::class,
        DoctorRevenueReport::class,
        AttendanceReport::class,
        SalaryReport::class,
    ];

    /** @return array<string, Report> keyed by slug */
    public static function all(): array
    {
        $reports = [];
        foreach (self::REPORTS as $class) {
            $report = app($class);
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public static function find(string $key): ?Report
    {
        return self::all()[$key] ?? null;
    }
}
