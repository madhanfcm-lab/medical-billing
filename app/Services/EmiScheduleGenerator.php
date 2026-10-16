<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Pure EMI schedule maths (no database).
 *
 * Give EITHER the number of months OR the monthly amount; the other is derived.
 *  - months mode:  every instalment is the amount/months rounded down to a whole rupee,
 *                  and the LAST instalment absorbs the rounding difference.
 *  - monthly mode: instalments of the monthly amount; the LAST one is the (smaller) remainder.
 *
 * The first due date is the first occurrence of the due day on/after the start date.
 * A due day beyond a short month (e.g. 31) falls on that month's last day.
 */
class EmiScheduleGenerator
{
    public const MAX_MONTHS = 60;

    public const BY_MONTHS = 'months';

    public const BY_MONTHLY = 'monthly';

    /**
     * @return array{installments: list<array{no: int, due_date: Carbon, amount: string}>, months: int, monthly_amount: string, first_due_date: Carbon, end_date: Carbon}
     */
    public function generate(
        float|string $amount,
        string $calcBy,
        int|string|null $months,
        float|string|null $monthlyAmount,
        CarbonInterface $startDate,
        int $dueDay,
        int $firstNumber = 1,
    ): array {
        $total = to_paise($amount);
        if ($total <= 0) {
            throw new InvalidArgumentException('There is nothing to schedule: the amount must be greater than zero.');
        }
        if ($dueDay < 1 || $dueDay > 31) {
            throw new InvalidArgumentException('Due day must be between 1 and 31.');
        }

        $amounts = match ($calcBy) {
            self::BY_MONTHS => $this->byMonths($total, (int) $months),
            self::BY_MONTHLY => $this->byMonthly($total, to_paise($monthlyAmount)),
            default => throw new InvalidArgumentException('Choose either number of months or monthly amount.'),
        };

        $first = $this->firstDueDate($startDate, $dueDay);
        $installments = [];
        foreach ($amounts as $i => $paise) {
            $installments[] = [
                'no' => $firstNumber + $i,
                'due_date' => $this->dueDateAfter($first, $i, $dueDay),
                'amount' => from_paise($paise),
            ];
        }

        return [
            'installments' => $installments,
            'months' => count($installments),
            'monthly_amount' => from_paise($amounts[0]),
            'first_due_date' => $first,
            'end_date' => end($installments)['due_date'],
        ];
    }

    /** @return list<int> paise per instalment */
    private function byMonths(int $total, int $months): array
    {
        if ($months < 1 || $months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('Number of months must be between 1 and '.self::MAX_MONTHS.'.');
        }

        $base = intdiv(intdiv($total, $months), 100) * 100;          // whole rupees
        if ($base < 100) {
            $base = intdiv($total, $months);                         // tiny amounts: fall back to paise
        }
        if ($base < 1) {
            throw new InvalidArgumentException('The amount is too small to split into '.$months.' instalments.');
        }

        $amounts = array_fill(0, $months, $base);
        $amounts[$months - 1] = $total - $base * ($months - 1);

        return $amounts;
    }

    /** @return list<int> */
    private function byMonthly(int $total, int $monthly): array
    {
        if ($monthly <= 0) {
            throw new InvalidArgumentException('Monthly amount must be greater than zero.');
        }

        $months = (int) ceil($total / $monthly);
        if ($months > self::MAX_MONTHS) {
            throw new InvalidArgumentException('That monthly amount needs '.$months.' instalments; the maximum is '.self::MAX_MONTHS.'. Increase the monthly amount.');
        }

        $amounts = array_fill(0, $months, $monthly);
        $amounts[$months - 1] = $total - $monthly * ($months - 1);

        return $amounts;
    }

    private function firstDueDate(CarbonInterface $start, int $dueDay): Carbon
    {
        $start = Carbon::parse($start->toDateString());
        $candidate = $this->onDay($start->copy()->startOfMonth(), $dueDay);

        return $candidate->lt($start)
            ? $this->onDay($start->copy()->startOfMonth()->addMonthNoOverflow(), $dueDay)
            : $candidate;
    }

    private function dueDateAfter(Carbon $first, int $monthsAfter, int $dueDay): Carbon
    {
        return $this->onDay($first->copy()->startOfMonth()->addMonthsNoOverflow($monthsAfter), $dueDay);
    }

    private function onDay(Carbon $monthStart, int $day): Carbon
    {
        return $monthStart->copy()->day(min($day, $monthStart->daysInMonth));
    }
}
