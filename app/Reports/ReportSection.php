<?php

namespace App\Reports;

/**
 * One table of a report.
 *
 * Column: ['key' => 'amount', 'label' => 'Amount', 'type' => 'text|money|int|date']
 * Rows are arrays keyed by column key holding RAW values (numbers, dates, strings): formatting is
 * done by whoever renders them (HTML, PDF or Excel), so Excel gets real numbers and real dates.
 */
final class ReportSection
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals  keyed like a row; shown as the last row
     * @param  int|null  $totalRows  rows that exist in total, when $rows is a truncated slice
     */
    public readonly array $rows;

    public readonly ?array $totals;

    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        array $rows,
        ?array $totals = null,
        public readonly ?int $totalRows = null,
        public readonly string $empty = 'Nothing to show for these filters.',
    ) {
        $money = array_column(array_filter($columns, fn ($c) => $c['type'] === 'money'), 'key');
        $normalise = function (array $row) use ($money): array {
            foreach ($money as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $row[$key] = from_paise(to_paise($row[$key]));
                }
            }

            return $row;
        };

        $this->rows = array_map($normalise, $rows);
        $this->totals = $totals === null ? null : $normalise($totals);
    }

    public function isTruncated(): bool
    {
        return $this->totalRows !== null && $this->totalRows > count($this->rows);
    }

    public static function column(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
