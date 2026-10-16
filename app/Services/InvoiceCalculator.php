<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Pure invoice maths (no database). The invoice form's JavaScript mirrors these
 * exact rules, so the live preview and the saved invoice always agree.
 *
 *   line gross   = quantity x unit price
 *   line taxable = gross - item discount
 *   line tax     = round(taxable x GST% / 100)
 *   subtotal     = sum of line taxable amounts
 *   bill discount comes off the subtotal (fixed ₹ or % of subtotal)
 *   tax          = sum of line tax, scaled by (subtotal - discount) / subtotal
 *   grand total  = subtotal - discount + tax
 *
 * Everything is calculated in integer paise; results are returned as "0.00" strings.
 */
class InvoiceCalculator
{
    public const DISCOUNT_AMOUNT = 'amount';

    public const DISCOUNT_PERCENT = 'percent';

    /**
     * @param  list<array{quantity: int|string, unit_price: float|string, discount_amount?: float|string|null, gst_percent?: float|string|null}>  $items
     * @return array{lines: list<array<string, string>>, subtotal: string, discount_amount: string, tax_amount: string, grand_total: string}
     */
    public function calculate(array $items, ?string $discountType = null, float|string|null $discountValue = null): array
    {
        $lines = [];
        $subtotal = 0;
        $rawTax = 0;

        foreach ($items as $item) {
            $gross = (int) $item['quantity'] * to_paise($item['unit_price']);
            $discount = to_paise($item['discount_amount'] ?? 0);
            if ($discount < 0 || $discount > $gross) {
                throw new InvalidArgumentException('Item discount cannot exceed the item amount.');
            }
            $taxable = $gross - $discount;
            $tax = (int) round($taxable * (float) ($item['gst_percent'] ?? 0) / 100);

            $lines[] = [
                'gross' => from_paise($gross), 'discount' => from_paise($discount), 'taxable' => from_paise($taxable),
                'tax' => from_paise($tax), 'total' => from_paise($taxable + $tax),
            ];
            $subtotal += $taxable;
            $rawTax += $tax;
        }

        $discountAmount = $this->billDiscount($subtotal, $discountType, $discountValue);
        $tax = $subtotal > 0 ? (int) round($rawTax * ($subtotal - $discountAmount) / $subtotal) : 0;

        return [
            'lines' => $lines,
            'subtotal' => from_paise($subtotal),
            'discount_amount' => from_paise($discountAmount),
            'tax_amount' => from_paise($tax),
            'grand_total' => from_paise($subtotal - $discountAmount + $tax),
        ];
    }

    private function billDiscount(int $subtotal, ?string $type, float|string|null $value): int
    {
        if ($type === null || $value === null || $value === '' || (float) $value <= 0) {
            return 0;
        }

        $discount = match ($type) {
            self::DISCOUNT_PERCENT => (float) $value > 100
                ? throw new InvalidArgumentException('Discount cannot exceed 100%.')
                : (int) round($subtotal * (float) $value / 100),
            self::DISCOUNT_AMOUNT => to_paise($value),
            default => throw new InvalidArgumentException('Unknown discount type.'),
        };

        if ($discount > $subtotal) {
            throw new InvalidArgumentException('Discount cannot exceed the subtotal.');
        }

        return $discount;
    }
}
