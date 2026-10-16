<?php

namespace App\Reports;

final class ReportResult
{
    /**
     * @param  list<array{label: string, value: mixed, type: string}>  $summary  headline figures
     * @param  list<ReportSection>  $sections
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $summary,
        public readonly array $sections,
    ) {}

    public static function figure(string $label, mixed $value, string $type = 'int'): array
    {
        if ($type === 'money' && is_numeric($value)) {
            $value = from_paise(to_paise($value));   // same "1234.50" string on every database
        }

        return ['label' => $label, 'value' => $value, 'type' => $type];
    }
}
