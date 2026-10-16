<?php

namespace App\Http\Requests\Emi;

use App\Services\EmiScheduleGenerator;

/** Shared rules for the "months OR monthly amount, start date, due day" part of every EMI form. */
trait ValidatesEmiSchedule
{
    /** @return array<string, array<int, mixed>> */
    protected function scheduleRules(bool $startMustNotBePast): array
    {
        return [
            'calc_by' => ['required', 'in:'.EmiScheduleGenerator::BY_MONTHS.','.EmiScheduleGenerator::BY_MONTHLY],
            'months' => ['required_if:calc_by,'.EmiScheduleGenerator::BY_MONTHS, 'nullable', 'integer', 'min:1', 'max:'.EmiScheduleGenerator::MAX_MONTHS],
            'monthly_amount' => ['required_if:calc_by,'.EmiScheduleGenerator::BY_MONTHLY, 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'start_date' => array_filter(['required', 'date_format:Y-m-d', $startMustNotBePast ? 'after_or_equal:today' : null]),
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
        ];
    }

    /** @return array<string, string> */
    protected function scheduleMessages(): array
    {
        return [
            'calc_by.required' => 'Choose whether to enter the number of months or the monthly amount.',
            'months.required_if' => 'Enter the number of months.',
            'months.min' => 'Number of months must be at least 1.',
            'months.max' => 'Number of months cannot be more than '.EmiScheduleGenerator::MAX_MONTHS.'.',
            'months.integer' => 'Number of months must be a whole number.',
            'monthly_amount.required_if' => 'Enter the monthly amount.',
            'monthly_amount.min' => 'Monthly amount must be at least ₹0.01.',
            'monthly_amount.decimal' => 'Monthly amount can have at most 2 decimal places.',
            'start_date.required' => 'Enter the start date.',
            'start_date.after_or_equal' => 'Start date cannot be in the past.',
            'due_day.required' => 'Enter the due day of the month.',
            'due_day.min' => 'Due day must be between 1 and 31.',
            'due_day.max' => 'Due day must be between 1 and 31.',
        ];
    }

    protected function normaliseBlanks(array $keys): void
    {
        foreach ($keys as $key => $default) {
            if ($this->input($key) === null || $this->input($key) === '') {
                $this->merge([$key => $default]);
            }
        }
    }
}
