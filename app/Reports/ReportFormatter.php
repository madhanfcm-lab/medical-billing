<?php

namespace App\Reports;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Turns raw report values into what the screen / PDF / Excel should show. */
final class ReportFormatter
{
    /** Text for HTML and PDF. */
    public static function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => money($value),
            'int' => number_format((float) $value, 0, '.', ','),
            'date' => format_date($value),
            default => (string) $value,
        };
    }

    /** Native cell value for Excel: real numbers and real dates. */
    public static function excel(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money' => round((float) $value, 2),
            'int' => (int) round((float) $value),
            'date' => ExcelDate::PHPToExcel($value instanceof DateTimeInterface ? $value : new \DateTimeImmutable((string) $value)),
            default => (string) $value,
        };
    }

    public static function excelFormat(string $type): string
    {
        return match ($type) {
            'money' => '#,##0.00',
            'int' => '#,##0',
            'date' => 'dd-mm-yyyy',
            default => '@',
        };
    }

    public static function isNumeric(string $type): bool
    {
        return in_array($type, ['money', 'int'], true);
    }
}
