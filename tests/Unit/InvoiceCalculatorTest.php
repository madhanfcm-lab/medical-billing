<?php

namespace Tests\Unit;

use App\Services\InvoiceCalculator;
use App\Support\AmountInWords;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../app/Support/helpers.php';

class InvoiceCalculatorTest extends TestCase
{
    private function calc(array $items, ?string $type = null, $value = null): array
    {
        return (new InvoiceCalculator)->calculate($items, $type, $value);
    }

    private function item(int $qty, string $price, string $disc = '0', string $gst = '0'): array
    {
        return ['quantity' => $qty, 'unit_price' => $price, 'discount_amount' => $disc, 'gst_percent' => $gst];
    }

    public function test_simple_total(): void
    {
        $r = $this->calc([$this->item(2, '500.00'), $this->item(1, '250.50')]);

        $this->assertSame('1250.50', $r['subtotal']);
        $this->assertSame('0.00', $r['discount_amount']);
        $this->assertSame('0.00', $r['tax_amount']);
        $this->assertSame('1250.50', $r['grand_total']);
    }

    public function test_item_discount_and_gst(): void
    {
        // 2 x 1000 = 2000, -200 discount = 1800 taxable, 18% GST = 324
        $r = $this->calc([$this->item(2, '1000', '200', '18')]);

        $this->assertSame('1800.00', $r['subtotal']);
        $this->assertSame('324.00', $r['tax_amount']);
        $this->assertSame('2124.00', $r['grand_total']);
        $this->assertSame('2124.00', $r['lines'][0]['total']);
    }

    public function test_bill_discount_amount_reduces_tax_proportionally(): void
    {
        // subtotal 1000, GST 18% = 180; ₹100 off => taxable 900 => tax 162; grand 1062
        $r = $this->calc([$this->item(1, '1000', '0', '18')], 'amount', '100');

        $this->assertSame('100.00', $r['discount_amount']);
        $this->assertSame('162.00', $r['tax_amount']);
        $this->assertSame('1062.00', $r['grand_total']);
    }

    public function test_bill_discount_percent(): void
    {
        $r = $this->calc([$this->item(1, '30000')], 'percent', '10');

        $this->assertSame('3000.00', $r['discount_amount']);
        $this->assertSame('27000.00', $r['grand_total']);
    }

    public function test_rounding_is_done_in_paise(): void
    {
        // 0.1 + 0.2 style float traps: 3 x 33.33 = 99.99, 5% GST = 5.00 (4.9995 rounds up)
        $r = $this->calc([$this->item(3, '33.33', '0', '5')]);

        $this->assertSame('99.99', $r['subtotal']);
        $this->assertSame('5.00', $r['tax_amount']);
        $this->assertSame('104.99', $r['grand_total']);
    }

    public function test_discount_equal_to_subtotal_gives_zero_total(): void
    {
        $r = $this->calc([$this->item(1, '500', '0', '12')], 'amount', '500');

        $this->assertSame('0.00', $r['grand_total']);
        $this->assertSame('0.00', $r['tax_amount']);
    }

    public function test_discount_above_subtotal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc([$this->item(1, '500')], 'amount', '500.01');
    }

    public function test_percent_above_100_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc([$this->item(1, '500')], 'percent', '101');
    }

    public function test_item_discount_above_item_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc([$this->item(1, '500', '600')]);
    }

    public function test_amount_in_words(): void
    {
        $this->assertSame('Rupees Thirty Thousand Only', AmountInWords::rupees('30000'));
        $this->assertSame('Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Fifty Paise Only', AmountInWords::rupees('123456.50'));
        $this->assertSame('Rupees Two Crore Only', AmountInWords::rupees('20000000'));
        $this->assertSame('Rupees Zero Only', AmountInWords::rupees('0'));
    }
}
