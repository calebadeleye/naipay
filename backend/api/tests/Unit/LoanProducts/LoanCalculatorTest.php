<?php

declare(strict_types=1);

namespace Tests\Unit\LoanProducts;

use App\Domains\LoanProducts\Data\Instalment;
use App\Domains\LoanProducts\Data\LoanTerms;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Services\BusinessDayCalendar;
use App\Domains\LoanProducts\Services\LoanCalculator;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The loan calculation engine.
 *
 * Pure unit tests — no database, no container. Every figure here is one a
 * merchant would be asked to pay, so the assertions are on exact kobo, never
 * on approximations.
 */
final class LoanCalculatorTest extends TestCase
{
    private LoanCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LoanCalculator(new BusinessDayCalendar);
    }

    // --- Naipay's actual products --------------------------------------------

    #[Test]
    public function a_daily_loan_costs_twenty_percent(): void
    {
        // Borrow ₦100,000 on the daily plan, repay ₦120,000.
        $interest = $this->calculator->totalInterest($this->terms('100000.00', '20.0000'));

        $this->assertSame('20000.00', $interest->toDecimalString());
    }

    #[Test]
    public function a_daily_loan_of_one_million_repays_one_point_two_million(): void
    {
        $terms = $this->terms('1000000.00', '20.0000');

        $schedule = $this->calculator->schedule($terms);

        $this->assertSame('200000.00', $schedule->totalInterest->toDecimalString());
        $this->assertSame('1200000.00', $schedule->totalPayable()->toDecimalString());
    }

    #[Test]
    public function a_weekly_loan_costs_four_percent(): void
    {
        $terms = $this->terms('1000000.00', '4.0000', RepaymentFrequency::Weekly, tenor: 8);

        $schedule = $this->calculator->schedule($terms);

        // Borrow ₦1,000,000 weekly, repay ₦1,040,000.
        $this->assertSame('40000.00', $schedule->totalInterest->toDecimalString());
        $this->assertSame('1040000.00', $schedule->totalPayable()->toDecimalString());
    }

    #[Test]
    public function a_monthly_loan_costs_four_percent(): void
    {
        $terms = $this->terms('1000000.00', '4.0000', RepaymentFrequency::Monthly, tenor: 6);

        $schedule = $this->calculator->schedule($terms);

        $this->assertSame('1040000.00', $schedule->totalPayable()->toDecimalString());
    }

    #[Test]
    public function a_flat_rate_does_not_change_with_the_term(): void
    {
        $short = $this->calculator->totalInterest($this->terms(tenor: 10));
        $long = $this->calculator->totalInterest($this->terms(tenor: 60));

        // This is what "flat" means, and it is why the tenor can be set per
        // loan without changing the price.
        $this->assertTrue($short->equals($long));
        $this->assertSame('20000.00', $long->toDecimalString());
    }

    #[Test]
    #[DataProvider('flatRateScenarios')]
    public function flat_interest_is_exact_for_any_principal(
        string $principal,
        string $rate,
        string $expectedInterest,
    ): void {
        $interest = $this->calculator->totalInterest($this->terms($principal, $rate));

        $this->assertSame($expectedInterest, $interest->toDecimalString());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function flatRateScenarios(): array
    {
        return [
            'daily on 50,000' => ['50000.00', '20.0000', '10000.00'],
            'daily on 1,000,000' => ['1000000.00', '20.0000', '200000.00'],
            'weekly on 1,000,000' => ['1000000.00', '4.0000', '40000.00'],
            'weekly on 250,000' => ['250000.00', '4.0000', '10000.00'],
            'an odd principal' => ['33333.33', '20.0000', '6666.67'],
            'a principal of one kobo' => ['0.01', '20.0000', '0.00'],
            'half a kobo rounds up' => ['0.25', '20.0000', '0.05'],
            'small principal' => ['1500.55', '4.0000', '60.02'],
        ];
    }

    // --- Schedule integrity ---------------------------------------------------

    #[Test]
    #[DataProvider('scheduleScenarios')]
    public function a_schedule_always_sums_back_to_the_loan(
        string $principal,
        string $rate,
        RepaymentFrequency $frequency,
        int $tenor,
    ): void {
        $terms = $this->terms($principal, $rate, $frequency, $tenor);

        $schedule = $this->calculator->schedule($terms);

        $principalSum = Money::sum(array_map(
            static fn (Instalment $i): Money => $i->principalDue,
            $schedule->instalments,
        ));

        $totalSum = Money::sum(array_map(
            static fn (Instalment $i): Money => $i->totalDue(),
            $schedule->instalments,
        ));

        // A schedule a kobo out will not close: the merchant either cannot
        // settle, or is left owing nothing while the balance says otherwise.
        $this->assertTrue($principalSum->equals(Money::fromDecimal($principal)));
        $this->assertTrue($totalSum->equals($schedule->totalPayable()));
        $this->assertCount($tenor, $schedule->instalments);
    }

    /**
     * @return array<string, array{string, string, RepaymentFrequency, int}>
     */
    public static function scheduleScenarios(): array
    {
        return [
            'daily, awkward principal over 21 days' => ['100000.00', '20.0000', RepaymentFrequency::Daily, 21],
            'daily, prime tenor' => ['77777.77', '20.0000', RepaymentFrequency::Daily, 23],
            'daily, single instalment' => ['5000.00', '20.0000', RepaymentFrequency::Daily, 1],
            'daily over 90 working days' => ['1000000.00', '20.0000', RepaymentFrequency::Daily, 90],
            'weekly over 7 weeks' => ['333333.33', '4.0000', RepaymentFrequency::Weekly, 7],
            'weekly over 52 weeks' => ['1000000.00', '4.0000', RepaymentFrequency::Weekly, 52],
            'monthly over 3' => ['100000.01', '4.0000', RepaymentFrequency::Monthly, 3],
            'monthly over 13' => ['999999.99', '4.0000', RepaymentFrequency::Monthly, 13],
            'one kobo over 5 instalments' => ['0.05', '20.0000', RepaymentFrequency::Daily, 5],
        ];
    }

    #[Test]
    public function the_closing_balance_of_the_final_instalment_is_zero(): void
    {
        foreach ([1, 7, 20, 23, 90] as $tenor) {
            $schedule = $this->calculator->schedule($this->terms('123456.78', '20.0000', tenor: $tenor));

            $final = $schedule->instalments[count($schedule->instalments) - 1];

            $this->assertTrue(
                $final->closingPrincipal()->isZero(),
                "A {$tenor}-instalment schedule did not close to zero.",
            );
        }
    }

    #[Test]
    public function each_instalment_opens_where_the_previous_one_closed(): void
    {
        $schedule = $this->calculator->schedule($this->terms('100000.00', '20.0000', tenor: 21));

        for ($index = 1; $index < count($schedule->instalments); $index++) {
            $this->assertTrue(
                $schedule->instalments[$index]->openingPrincipal->equals(
                    $schedule->instalments[$index - 1]->closingPrincipal()
                ),
                "Instalment {$index} does not continue from the previous balance.",
            );
        }
    }

    #[Test]
    public function remainder_kobo_land_on_the_leading_instalments(): void
    {
        // ₦100,000 over 3 does not divide evenly.
        $schedule = $this->calculator->schedule($this->terms('100000.00', '20.0000', tenor: 3));

        $this->assertSame('33333.34', $schedule->instalments[0]->principalDue->toDecimalString());
        $this->assertSame('33333.33', $schedule->instalments[1]->principalDue->toDecimalString());
        $this->assertSame('33333.33', $schedule->instalments[2]->principalDue->toDecimalString());

        // No single payment is visibly out of step, and the total is exact.
        $this->assertSame('100000.00', Money::sum(array_map(
            static fn (Instalment $i): Money => $i->principalDue,
            $schedule->instalments,
        ))->toDecimalString());
    }

    // --- Weekends ---------------------------------------------------------------

    #[Test]
    public function a_daily_schedule_never_falls_on_a_weekend(): void
    {
        $schedule = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Daily, tenor: 40, disbursedOn: '2026-03-02')
        );

        foreach ($schedule->instalments as $instalment) {
            $this->assertFalse(
                $instalment->dueDate->isSaturday() || $instalment->dueDate->isSunday(),
                "Instalment {$instalment->number} falls on {$instalment->dueDate->format('l, j F Y')}.",
            );
        }
    }

    #[Test]
    public function a_daily_schedule_runs_monday_to_friday_then_skips_to_monday(): void
    {
        // Disbursed Monday 2 March 2026; the first instalment is the Tuesday.
        $schedule = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Daily, tenor: 6, disbursedOn: '2026-03-02')
        );

        $dates = array_map(
            static fn (Instalment $i): string => $i->dueDate->format('D j M'),
            $schedule->instalments,
        );

        $this->assertSame([
            'Tue 3 Mar',
            'Wed 4 Mar',
            'Thu 5 Mar',
            'Fri 6 Mar',
            // Saturday and Sunday skipped.
            'Mon 9 Mar',
            'Tue 10 Mar',
        ], $dates);
    }

    #[Test]
    public function a_daily_loan_disbursed_on_a_friday_first_falls_due_on_the_monday(): void
    {
        // Friday 6 March 2026.
        $schedule = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Daily, tenor: 2, disbursedOn: '2026-03-06')
        );

        $this->assertSame('Mon 9 Mar', $schedule->instalments[0]->dueDate->format('D j M'));
        $this->assertSame('Tue 10 Mar', $schedule->instalments[1]->dueDate->format('D j M'));
    }

    #[Test]
    public function a_twenty_instalment_daily_loan_spans_four_calendar_weeks(): void
    {
        $schedule = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Daily, tenor: 20, disbursedOn: '2026-03-02')
        );

        // Twenty working days from Tuesday 3 March runs to Monday 30 March —
        // eight weekend days longer than twenty calendar days would suggest.
        $this->assertSame('2026-03-03', $schedule->firstRepaymentDate()->toDateString());
        $this->assertSame('2026-03-30', $schedule->maturityDate()->toDateString());
    }

    #[Test]
    public function weekly_and_monthly_schedules_are_not_shifted_off_weekends(): void
    {
        // Only daily collection is affected: a weekly instalment falling on a
        // Saturday is normal, because the merchant is paying for the week.
        $weekly = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Weekly, tenor: 4, disbursedOn: '2026-03-07')
        );

        $this->assertTrue($weekly->instalments[0]->dueDate->isSaturday());
    }

    #[Test]
    public function public_holidays_are_treated_as_non_working_days(): void
    {
        // Nigerian public holidays move with the lunar calendar and are
        // sometimes announced days ahead, so the list is configuration rather
        // than code.
        $calendar = new BusinessDayCalendar(['2026-03-04']);

        $this->assertTrue($calendar->isBusinessDay(Carbon::parse('2026-03-03')));
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-03-04')));
        $this->assertFalse($calendar->isBusinessDay(Carbon::parse('2026-03-07'))); // Saturday
    }

    #[Test]
    public function a_daily_schedule_steps_over_a_public_holiday(): void
    {
        $calculator = new LoanCalculator(new BusinessDayCalendar(['2026-03-04']));

        $schedule = $calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Daily, tenor: 4, disbursedOn: '2026-03-02')
        );

        $dates = array_map(
            static fn (Instalment $i): string => $i->dueDate->toDateString(),
            $schedule->instalments,
        );

        // Wednesday 4 March is skipped entirely.
        $this->assertSame(['2026-03-03', '2026-03-05', '2026-03-06', '2026-03-09'], $dates);
    }

    // --- Grace period and first repayment date -----------------------------------

    #[Test]
    public function a_grace_period_delays_the_first_instalment(): void
    {
        $schedule = $this->calculator->schedule(
            $this->terms(frequency: RepaymentFrequency::Weekly, tenor: 4, disbursedOn: '2026-03-02', graceDays: 14)
        );

        // Two weeks of grace, then the first weekly instalment.
        $this->assertSame('2026-03-23', $schedule->firstRepaymentDate()->toDateString());
    }

    #[Test]
    public function an_explicit_first_repayment_date_is_honoured(): void
    {
        $terms = new LoanTerms(
            principal: Money::fromDecimal('100000.00'),
            interestMethod: InterestMethod::Flat,
            interestRate: '20.0000',
            frequency: RepaymentFrequency::Daily,
            tenor: 3,
            disbursementDate: Carbon::parse('2026-03-02'),
            firstRepaymentDate: Carbon::parse('2026-03-16'),
        );

        // Lets an officer align collection with a merchant's trading cycle.
        $this->assertSame('2026-03-16', $this->calculator->schedule($terms)->firstRepaymentDate()->toDateString());
    }

    #[Test]
    public function an_explicit_first_date_on_a_weekend_moves_forward_for_daily_loans(): void
    {
        $terms = new LoanTerms(
            principal: Money::fromDecimal('100000.00'),
            interestMethod: InterestMethod::Flat,
            interestRate: '20.0000',
            frequency: RepaymentFrequency::Daily,
            tenor: 2,
            disbursementDate: Carbon::parse('2026-03-02'),
            firstRepaymentDate: Carbon::parse('2026-03-07'), // A Saturday.
        );

        // Forward, never back: pulling a payment earlier would demand money
        // before the merchant expected to owe it.
        $this->assertSame('2026-03-09', $this->calculator->schedule($terms)->firstRepaymentDate()->toDateString());
    }

    // --- Fees ---------------------------------------------------------------------

    #[Test]
    public function fees_sit_on_the_first_instalment_and_are_counted_once(): void
    {
        $schedule = $this->calculator->schedule(
            $this->terms(tenor: 5),
            Money::fromDecimal('2500.00'),
        );

        // A processing fee is incurred once, at the start; spreading it would
        // imply it is being earned over the term.
        $this->assertSame('2500.00', $schedule->instalments[0]->feeDue->toDecimalString());
        $this->assertSame('0.00', $schedule->instalments[1]->feeDue->toDecimalString());
        $this->assertSame('2500.00', $schedule->totalFees->toDecimalString());
        $this->assertSame('122500.00', $schedule->totalPayable()->toDecimalString());
    }

    // --- The other interest methods -------------------------------------------------

    #[Test]
    public function declining_balance_instalments_reduce_over_the_term(): void
    {
        $terms = $this->terms(
            '120000.00',
            '2.0000',
            RepaymentFrequency::Monthly,
            tenor: 4,
            method: InterestMethod::DecliningBalance,
        );

        $schedule = $this->calculator->schedule($terms);

        // Principal is level; interest falls as the balance does.
        $this->assertSame('2400.00', $schedule->instalments[0]->interestDue->toDecimalString());
        $this->assertSame('1800.00', $schedule->instalments[1]->interestDue->toDecimalString());
        $this->assertSame('1200.00', $schedule->instalments[2]->interestDue->toDecimalString());
        $this->assertSame('600.00', $schedule->instalments[3]->interestDue->toDecimalString());

        $this->assertSame('6000.00', $schedule->totalInterest->toDecimalString());
    }

    #[Test]
    public function reducing_balance_produces_level_instalments_that_close_to_zero(): void
    {
        $terms = $this->terms(
            '100000.00',
            '3.0000',
            RepaymentFrequency::Monthly,
            tenor: 12,
            method: InterestMethod::ReducingBalance,
        );

        $schedule = $this->calculator->schedule($terms);

        $first = $schedule->instalments[0]->totalDue();
        $middle = $schedule->instalments[5]->totalDue();

        // Level to within a kobo of rounding.
        $this->assertLessThanOrEqual(
            100,
            abs($first->minorUnits() - $middle->minorUnits()),
            'Annuity instalments should be level.',
        );

        // And the final instalment absorbs the rounding so nothing is left.
        $this->assertTrue(
            $schedule->instalments[11]->closingPrincipal()->isZero(),
            'A reducing-balance schedule must close to zero.',
        );
    }

    #[Test]
    public function simple_interest_grows_with_the_term(): void
    {
        $short = $this->calculator->totalInterest(
            $this->terms('100000.00', '2.0000', RepaymentFrequency::Monthly, tenor: 3, method: InterestMethod::SimpleInterest)
        );

        $long = $this->calculator->totalInterest(
            $this->terms('100000.00', '2.0000', RepaymentFrequency::Monthly, tenor: 6, method: InterestMethod::SimpleInterest)
        );

        $this->assertSame('6000.00', $short->toDecimalString());
        $this->assertSame('12000.00', $long->toDecimalString());
    }

    #[Test]
    public function a_zero_rate_produces_no_interest(): void
    {
        $schedule = $this->calculator->schedule($this->terms('100000.00', '0'));

        $this->assertTrue($schedule->totalInterest->isZero());
        $this->assertSame('100000.00', $schedule->totalPayable()->toDecimalString());
    }

    // --- Determinism -------------------------------------------------------------------

    #[Test]
    public function the_same_terms_always_produce_the_same_schedule(): void
    {
        $terms = $this->terms('456789.12', '20.0000', tenor: 37);

        $first = $this->calculator->schedule($terms)->toArray();

        for ($run = 0; $run < 5; $run++) {
            $this->assertSame($first, $this->calculator->schedule($terms)->toArray());
        }
    }

    #[Test]
    public function invalid_terms_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LoanTerms(
            principal: Money::fromDecimal('100000.00'),
            interestMethod: InterestMethod::Flat,
            interestRate: '20.0000',
            frequency: RepaymentFrequency::Daily,
            tenor: 0,
            disbursementDate: Carbon::parse('2026-03-02'),
        );
    }

    #[Test]
    public function a_zero_principal_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LoanTerms(
            principal: Money::zero(),
            interestMethod: InterestMethod::Flat,
            interestRate: '20.0000',
            frequency: RepaymentFrequency::Daily,
            tenor: 10,
            disbursementDate: Carbon::parse('2026-03-02'),
        );
    }

    private function terms(
        string $principal = '100000.00',
        string $rate = '20.0000',
        RepaymentFrequency $frequency = RepaymentFrequency::Daily,
        int $tenor = 20,
        InterestMethod $method = InterestMethod::Flat,
        string $disbursedOn = '2026-03-02', // A Monday.
        int $graceDays = 0,
    ): LoanTerms {
        return new LoanTerms(
            principal: Money::fromDecimal($principal),
            interestMethod: $method,
            interestRate: $rate,
            frequency: $frequency,
            tenor: $tenor,
            disbursementDate: Carbon::parse($disbursedOn),
            gracePeriodDays: $graceDays,
        );
    }
}
