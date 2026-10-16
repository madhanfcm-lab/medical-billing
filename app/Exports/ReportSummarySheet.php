<?php

namespace App\Exports;

use App\Reports\ReportFormatter;
use App\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportSummarySheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    public function __construct(private readonly ReportResult $report) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function array(): array
    {
        $rows = [[$this->report->title], [$this->report->subtitle], [''], ['Figure', 'Value']];
        foreach ($this->report->summary as $f) {
            $rows[] = [$f['label'], ReportFormatter::excel($f['value'], $f['type'])];
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet): array
    {
        // numbers in the Value column keep their own type; apply a format per cell
        foreach ($this->report->summary as $i => $f) {
            $sheet->getStyle('B'.(5 + $i))->getNumberFormat()->setFormatCode(ReportFormatter::excelFormat($f['type']));
            $sheet->getStyle('B'.(5 + $i))->getAlignment()->setHorizontal('right');
        }

        return [1 => ['font' => ['bold' => true, 'size' => 14]], 4 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E6F2F2']]]];
    }
}
