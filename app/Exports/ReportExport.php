<?php

namespace App\Exports;

use App\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** One workbook per report: a Summary sheet, then one sheet per table. */
class ReportExport implements Export, WithMultipleSheets
{
    public function __construct(private readonly ReportResult $report) {}

    public function sheets(): array
    {
        $sheets = [];
        $used = [];

        if ($this->report->summary) {
            $sheets[] = new ReportSummarySheet($this->report);
            $used[] = 'summary';
        }
        foreach ($this->report->sections as $section) {
            $sheets[] = new ReportSectionSheet($this->report, $section, self::uniqueTitle($section->title, $used));
        }

        return $sheets;
    }

    /** Excel sheet names: max 31 chars, no : \ / ? * [ ], unique (case-insensitive). */
    private static function uniqueTitle(string $title, array &$used): string
    {
        $base = mb_substr(trim(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title)), 0, 28) ?: 'Sheet';
        $name = $base;
        for ($i = 2; in_array(mb_strtolower($name), $used, true); $i++) {
            $name = $base.' '.$i;
        }
        $used[] = mb_strtolower($name);

        return $name;
    }
}
