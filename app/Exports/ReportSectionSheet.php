<?php

namespace App\Exports;

use App\Reports\ReportFormatter;
use App\Reports\ReportResult;
use App\Reports\ReportSection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Layout: title, filters, blank, headings, data rows, totals row. Numbers and dates are native Excel values. */
class ReportSectionSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles, WithTitle
{
    private const HEADING_ROW = 4;

    public function __construct(
        private readonly ReportResult $report,
        private readonly ReportSection $section,
        private readonly string $sheetTitle,
    ) {}

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function array(): array
    {
        $cols = $this->section->columns;
        $rows = [
            [$this->report->title.' — '.$this->section->title],
            [$this->report->subtitle],
            [''],   // blank spacer row (a truly empty array would be dropped and shift everything up)
            array_column($cols, 'label'),
        ];

        foreach ($this->section->rows as $row) {
            $rows[] = array_map(fn ($c) => ReportFormatter::excel($row[$c['key']] ?? null, $c['type']), $cols);
        }
        if ($this->section->totals !== null) {
            $rows[] = array_map(fn ($c) => ReportFormatter::excel($this->section->totals[$c['key']] ?? null, $c['type'] === 'date' ? 'text' : $c['type']), $cols);
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->section->columns as $i => $c) {
            $formats[Coordinate::stringFromColumnIndex($i + 1)] = ReportFormatter::excelFormat($c['type']);
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $last = Coordinate::stringFromColumnIndex(max(count($this->section->columns), 1));
        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            self::HEADING_ROW => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E6F2F2']]],
        ];

        if ($this->section->totals !== null) {
            $totalRow = self::HEADING_ROW + count($this->section->rows) + 1;
            $styles[$totalRow] = ['font' => ['bold' => true], 'borders' => ['top' => ['borderStyle' => 'thin']]];
        }
        foreach ($this->section->columns as $i => $c) {
            if (ReportFormatter::isNumeric($c['type'])) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->getStyle("{$letter}".self::HEADING_ROW)->getAlignment()->setHorizontal('right');
            }
        }
        $sheet->freezePane('A'.(self::HEADING_ROW + 1));
        $sheet->setAutoFilter('A'.self::HEADING_ROW.':'.$last.(self::HEADING_ROW + count($this->section->rows)));

        return $styles;
    }
}
