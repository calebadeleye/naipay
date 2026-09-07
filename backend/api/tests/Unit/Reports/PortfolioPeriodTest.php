<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Domains\Reports\Support\PortfolioPeriod;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PortfolioPeriodTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, mid-month, mid-quarter — nothing lands on a boundary.
        $this->now = Carbon::create(2026, 5, 13, 14, 30, 0);
    }

    #[Test]
    public function no_range_is_this_month_to_date_and_is_flagged_default(): void
    {
        $period = PortfolioPeriod::resolve(null, null, null, $this->now);

        $this->assertSame('this_month', $period->key);
        $this->assertTrue($period->isDefault);
        $this->assertSame('2026-05-01', $period->from->toDateString());
        $this->assertSame('2026-05-13', $period->to->toDateString());
    }

    #[Test]
    public function last_month_is_the_whole_previous_calendar_month(): void
    {
        $period = PortfolioPeriod::resolve('last_month', null, null, $this->now);

        $this->assertSame('2026-04-01', $period->from->toDateString());
        $this->assertSame('2026-04-30', $period->to->toDateString());
        $this->assertFalse($period->isDefault);
    }

    #[Test]
    public function this_week_starts_on_monday(): void
    {
        $period = PortfolioPeriod::resolve('this_week', null, null, $this->now);

        // 2026-05-13 is a Wednesday; the Monday of that week is the 11th.
        $this->assertSame('2026-05-11', $period->from->toDateString());
        $this->assertSame('Monday', $period->from->format('l'));
    }

    #[Test]
    public function last_quarter_is_q1_when_now_is_in_q2(): void
    {
        $period = PortfolioPeriod::resolve('last_quarter', null, null, $this->now);

        $this->assertSame('2026-01-01', $period->from->toDateString());
        $this->assertSame('2026-03-31', $period->to->toDateString());
    }

    #[Test]
    public function last_year_is_the_whole_previous_calendar_year(): void
    {
        $period = PortfolioPeriod::resolve('last_year', null, null, $this->now);

        $this->assertSame('2025-01-01', $period->from->toDateString());
        $this->assertSame('2025-12-31', $period->to->toDateString());
    }

    #[Test]
    public function a_custom_range_uses_the_given_bounds(): void
    {
        $period = PortfolioPeriod::resolve('custom', '2026-02-01', '2026-02-28', $this->now);

        $this->assertSame('2026-02-01', $period->from->toDateString());
        $this->assertSame('2026-02-28', $period->to->toDateString());
    }

    #[Test]
    public function a_custom_range_without_both_bounds_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PortfolioPeriod::resolve('custom', '2026-02-01', null, $this->now);
    }

    #[Test]
    public function a_custom_range_that_ends_before_it_starts_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PortfolioPeriod::resolve('custom', '2026-03-01', '2026-02-01', $this->now);
    }

    #[Test]
    public function an_unknown_preset_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PortfolioPeriod::resolve('since_the_beginning_of_time', null, null, $this->now);
    }

    #[Test]
    public function the_previous_window_is_equal_length_and_immediately_before(): void
    {
        $period = PortfolioPeriod::resolve('custom', '2026-03-01', '2026-03-31', $this->now);

        // 31 days ending the day before the window opens.
        $this->assertSame('2026-02-28', $period->previousTo->toDateString());
        $this->assertSame('2026-01-29', $period->previousFrom->toDateString());
    }

    #[Test]
    public function granularity_scales_with_the_span(): void
    {
        $this->assertSame('day', PortfolioPeriod::resolve('custom', '2026-05-01', '2026-05-20', $this->now)->granularity());
        $this->assertSame('week', PortfolioPeriod::resolve('custom', '2026-01-01', '2026-04-01', $this->now)->granularity());
        $this->assertSame('month', PortfolioPeriod::resolve('custom', '2025-01-01', '2026-01-01', $this->now)->granularity());
    }

    #[Test]
    public function all_time_disables_comparison(): void
    {
        $period = PortfolioPeriod::resolve('all_time', null, null, $this->now);

        $this->assertFalse($period->comparisonMeaningful);
        $this->assertSame('2026-05-13', $period->to->toDateString());
    }
}
