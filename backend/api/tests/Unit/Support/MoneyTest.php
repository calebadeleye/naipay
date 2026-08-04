<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money\Money;
use App\Support\Money\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_decimal_strings_exactly(): void
    {
        $this->assertSame(12_500_050, Money::fromDecimal('125000.50')->minorUnits());
        $this->assertSame(100, Money::fromDecimal('1')->minorUnits());
        $this->assertSame(0, Money::fromDecimal('0.00')->minorUnits());
        $this->assertSame(-4_550, Money::fromDecimal('-45.50')->minorUnits());
        $this->assertSame(5, Money::fromDecimal('0.05')->minorUnits());
    }

    #[Test]
    public function it_round_trips_through_the_database_representation(): void
    {
        foreach (['0.00', '0.01', '999999999.99', '-1250.75', '1000000.00'] as $decimal) {
            $this->assertSame($decimal, Money::fromDecimal($decimal)->toDecimalString());
        }
    }

    #[Test]
    public function it_rejects_precision_beyond_two_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('100.005');
    }

    #[Test]
    public function it_allows_trailing_zeros_beyond_scale(): void
    {
        // "100.5000" is exactly 100.50 — no precision is actually being lost.
        $this->assertSame('100.50', Money::fromDecimal('100.5000')->toDecimalString());
    }

    #[Test]
    public function it_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1,000.00');
    }

    #[Test]
    public function the_classic_floating_point_error_does_not_occur(): void
    {
        $sum = Money::fromDecimal('0.10')->plus(Money::fromDecimal('0.20'));

        $this->assertTrue($sum->equals(Money::fromDecimal('0.30')));
        $this->assertSame('0.30', $sum->toDecimalString());
    }

    #[Test]
    public function it_adds_and_subtracts(): void
    {
        $a = Money::fromDecimal('1500.25');
        $b = Money::fromDecimal('499.80');

        $this->assertSame('2000.05', $a->plus($b)->toDecimalString());
        $this->assertSame('1000.45', $a->minus($b)->toDecimalString());
        $this->assertSame('-1000.45', $b->minus($a)->toDecimalString());
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('100.00', 'NGN')->plus(Money::fromDecimal('100.00', 'USD'));
    }

    #[Test]
    #[DataProvider('interestScenarios')]
    public function it_multiplies_by_a_rate_without_floating_point_drift(
        string $principal,
        string $rate,
        string $expected,
    ): void {
        $this->assertSame($expected, Money::fromDecimal($principal)->multiplyBy($rate)->toDecimalString());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function interestScenarios(): array
    {
        return [
            'flat 5% of 100,000' => ['100000.00', '0.05', '5000.00'],
            'monthly 2.5% of 250,000' => ['250000.00', '0.025', '6250.00'],
            'exactly half a kobo rounds up' => ['1.00', '0.005', '0.01'],
            'just under half a kobo rounds down' => ['1000.10', '0.005', '5.00'],
            'tiny rate on a small balance' => ['0.10', '0.01', '0.00'],
            'zero rate' => ['500000.00', '0', '0.00'],
            'whole multiplier' => ['1250.55', '3', '3751.65'],
        ];
    }

    #[Test]
    public function rounding_modes_are_honoured(): void
    {
        // 100.005 exactly — half a kobo.
        $base = Money::fromDecimal('10.00');

        $this->assertSame('1.01', $base->multiplyBy('0.1005', RoundingMode::HalfUp)->toDecimalString());
        $this->assertSame('1.00', $base->multiplyBy('0.1005', RoundingMode::HalfDown)->toDecimalString());
        $this->assertSame('1.01', $base->multiplyBy('0.1001', RoundingMode::Up)->toDecimalString());
        $this->assertSame('1.00', $base->multiplyBy('0.1009', RoundingMode::Down)->toDecimalString());
    }

    #[Test]
    public function negative_amounts_round_symmetrically(): void
    {
        // Reversal entries must mirror the original posting exactly, so a
        // negative amount has to round to the same magnitude as its positive.
        $positive = Money::fromDecimal('10.00')->multiplyBy('0.1005', RoundingMode::HalfUp);
        $negative = Money::fromDecimal('-10.00')->multiplyBy('0.1005', RoundingMode::HalfUp);

        $this->assertSame('1.01', $positive->toDecimalString());
        $this->assertSame('-1.01', $negative->toDecimalString());
        $this->assertTrue($positive->plus($negative)->isZero());
    }

    #[Test]
    public function even_allocation_never_loses_or_invents_a_kobo(): void
    {
        // 100,000.00 over 7 instalments does not divide evenly.
        $total = Money::fromDecimal('100000.00');
        $slices = $total->allocateEvenly(7);

        $this->assertCount(7, $slices);
        $this->assertTrue(Money::sum($slices)->equals($total));

        // Remainder kobo land on the leading instalments.
        $this->assertSame('14285.72', $slices[0]->toDecimalString());
        $this->assertSame('14285.71', $slices[6]->toDecimalString());
    }

    #[Test]
    #[DataProvider('allocationScenarios')]
    public function allocation_sums_back_to_the_original(string $amount, int $parts): void
    {
        $total = Money::fromDecimal($amount);

        $this->assertTrue(Money::sum($total->allocateEvenly($parts))->equals($total));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function allocationScenarios(): array
    {
        return [
            'typical micro loan over 12 months' => ['250000.00', 12],
            'odd amount over 3' => ['1000.01', 3],
            'one kobo over 5' => ['0.01', 5],
            'single instalment' => ['75000.00', 1],
            'weekly over 52' => ['1000000.00', 52],
            'negative reversal split' => ['-999.99', 7],
        ];
    }

    #[Test]
    public function allocation_requires_at_least_one_part(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('100.00')->allocateEvenly(0);
    }

    #[Test]
    public function capping_takes_the_lesser_amount(): void
    {
        $payment = Money::fromDecimal('50000.00');
        $penaltyDue = Money::fromDecimal('1250.00');

        // The workhorse of repayment allocation: take what is due, no more.
        $this->assertSame('1250.00', $payment->cappedAt($penaltyDue)->toDecimalString());
        $this->assertSame('1250.00', $penaltyDue->cappedAt($payment)->toDecimalString());
    }

    #[Test]
    public function it_divides_with_rounding(): void
    {
        $this->assertSame('333.33', Money::fromDecimal('1000.00')->divideBy(3)->toDecimalString());
        $this->assertSame('250.00', Money::fromDecimal('1000.00')->divideBy(4)->toDecimalString());
    }

    #[Test]
    public function it_refuses_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('100.00')->divideBy(0);
    }

    #[Test]
    public function comparisons_behave(): void
    {
        $small = Money::fromDecimal('100.00');
        $large = Money::fromDecimal('100.01');

        $this->assertTrue($large->greaterThan($small));
        $this->assertTrue($small->lessThan($large));
        $this->assertTrue($small->lessThanOrEqualTo(Money::fromDecimal('100.00')));
        $this->assertTrue($small->greaterThanOrEqualTo(Money::fromDecimal('100.00')));
        $this->assertFalse($small->equals($large));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue($large->isPositive());
        $this->assertTrue($large->negated()->isNegative());
    }

    #[Test]
    public function summing_an_empty_set_yields_zero(): void
    {
        $this->assertTrue(Money::sum([])->isZero());
    }

    #[Test]
    public function it_formats_for_receipts_and_statements(): void
    {
        $this->assertSame('₦1,250,000.50', Money::fromDecimal('1250000.50')->format());
        $this->assertSame('1,250,000.50', Money::fromDecimal('1250000.50')->format(withSymbol: false));
        $this->assertSame('₦0.05', Money::fromDecimal('0.05')->format());
        $this->assertSame('-₦45.50', Money::fromDecimal('-45.50')->format());
    }

    #[Test]
    public function it_handles_amounts_at_the_top_of_the_decimal_column(): void
    {
        // DECIMAL(20,2) permits 18 integer digits; the portfolio will never
        // approach this, but the value object must not silently overflow.
        $large = Money::fromDecimal('999999999999.99');

        $this->assertSame('999999999999.99', $large->toDecimalString());
        $this->assertSame('1999999999999.98', $large->plus($large)->toDecimalString());
    }

    #[Test]
    public function it_serialises_for_api_responses(): void
    {
        $this->assertSame([
            'amount' => '1250.75',
            'minor_units' => 125_075,
            'currency' => 'NGN',
            'formatted' => '₦1,250.75',
        ], Money::fromDecimal('1250.75')->jsonSerialize());
    }
}
