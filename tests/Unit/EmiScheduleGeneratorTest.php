<?php

namespace Tests\Unit;

use App\Services\EmiScheduleGenerator;
use App\Support\Whatsapp;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../app/Support/helpers.php';

class EmiScheduleGeneratorTest extends TestCase
{
    private function gen(string $amount, string $by, $months, $monthly, string $start = '2026-10-15', int $day = 5, int $first = 1): array
    {
        return (new EmiScheduleGenerator)->generate($amount, $by, $months, $monthly, Carbon::parse($start), $day, $first);
    }

    private function amounts(array $s): array
    {
        return array_column($s['installments'], 'amount');
    }

    public function test_braces_example_30000_at_3000_a_month_is_ten_instalments(): void
    {
        $s = $this->gen('30000', 'monthly', null, '3000');

        $this->assertSame(10, $s['months']);
        $this->assertSame(array_fill(0, 10, '3000.00'), $this->amounts($s));
        $this->assertSame('3000.00', $s['monthly_amount']);
        $this->assertSame('2026-11-05', $s['first_due_date']->toDateString());   // 5th already passed in October
        $this->assertSame('2027-08-05', $s['end_date']->toDateString());
    }

    public function test_months_mode_last_instalment_absorbs_rounding(): void
    {
        $s = $this->gen('10000', 'months', 3, null);

        $this->assertSame(['3333.00', '3333.00', '3334.00'], $this->amounts($s));
        $this->assertSame('3333.00', $s['monthly_amount']);
    }

    public function test_monthly_mode_last_instalment_is_the_remainder(): void
    {
        $s = $this->gen('10000', 'monthly', null, '3000');

        $this->assertSame(['3000.00', '3000.00', '3000.00', '1000.00'], $this->amounts($s));
        $this->assertSame(4, $s['months']);
    }

    public function test_instalments_always_add_up_to_the_amount(): void
    {
        foreach (['100.00', '9999.99', '12345.67', '1.00', '250000'] as $amount) {
            foreach ([1, 2, 3, 7, 12, 24, 60] as $months) {
                if (to_paise($amount) < $months) {
                    continue;
                }
                $s = $this->gen($amount, 'months', $months, null);
                $this->assertSame(to_paise($amount), array_sum(array_map('to_paise', $this->amounts($s))), "$amount / $months");
                $this->assertCount($months, $s['installments']);
                $this->assertGreaterThan(0, to_paise(end($s['installments'])['amount']));
            }
        }
    }

    public function test_tiny_amounts_fall_back_to_paise(): void
    {
        $s = $this->gen('5', 'months', 10, null);

        $this->assertSame(array_fill(0, 10, '0.50'), $this->amounts($s));
    }

    public function test_single_instalment(): void
    {
        $s = $this->gen('750', 'months', 1, null);
        $this->assertSame(['750.00'], $this->amounts($s));

        $s = $this->gen('750', 'monthly', null, '5000');   // monthly bigger than the amount
        $this->assertSame(['750.00'], $this->amounts($s));
    }

    public function test_first_due_date_is_next_occurrence_of_due_day(): void
    {
        $this->assertSame('2026-10-20', $this->gen('100', 'months', 1, null, '2026-10-15', 20)['first_due_date']->toDateString());
        $this->assertSame('2026-10-15', $this->gen('100', 'months', 1, null, '2026-10-15', 15)['first_due_date']->toDateString());  // today counts
        $this->assertSame('2026-11-14', $this->gen('100', 'months', 1, null, '2026-10-15', 14)['first_due_date']->toDateString());
        $this->assertSame('2027-01-05', $this->gen('100', 'months', 1, null, '2026-12-20', 5)['first_due_date']->toDateString());  // year rollover
    }

    public function test_due_day_beyond_short_month_uses_last_day(): void
    {
        $dates = array_map(fn ($i) => $i['due_date']->toDateString(), $this->gen('400', 'months', 4, null, '2027-01-31', 31)['installments']);

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], $dates);
    }

    public function test_numbering_can_continue_after_a_reschedule(): void
    {
        $s = $this->gen('300', 'months', 3, null, '2026-10-15', 5, 11);

        $this->assertSame([11, 12, 13], array_column($s['installments'], 'no'));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $bad = [
            fn () => $this->gen('30000', 'months', 0, null),
            fn () => $this->gen('30000', 'months', 61, null),
            fn () => $this->gen('30000', 'monthly', null, '0'),
            fn () => $this->gen('30000', 'monthly', null, '100'),      // would need 300 instalments
            fn () => $this->gen('0', 'months', 3, null),
            fn () => $this->gen('30000', 'months', 3, null, '2026-10-15', 0),
            fn () => $this->gen('30000', 'months', 3, null, '2026-10-15', 32),
            fn () => $this->gen('30000', 'weekly', 3, null),
            fn () => $this->gen('0.05', 'months', 10, null),           // 5 paise over 10 instalments
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                $this->fail("Case $i should have been rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_whatsapp_link(): void
    {
        $link = Whatsapp::link('98765 43210', 'Hello Asha & family: ₹3,000 due');

        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $link);
        $this->assertStringContainsString('Hello%20Asha%20%26%20family', $link);
        $this->assertSame('Hello Asha & family: ₹3,000 due', rawurldecode(substr($link, strpos($link, 'text=') + 5)));
    }
}
