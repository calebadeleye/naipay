<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Resolves a dashboard date filter into a concrete window.
 *
 * The portfolio dashboard reports two kinds of figure and this class fixes the
 * reference points for both:
 *
 *  - flow metrics (disbursed, collected, expected) are measured over
 *    [`from`, `to`];
 *  - stock metrics (outstanding, PAR, ageing, active counts) are measured as
 *    at `asOf()`, which is the end of the window — "the portfolio as it stood
 *    on that date", not "movements during it".
 *
 * `previousFrom`/`previousTo` is the equ-length window immediately before, used
 * only for period-over-period comparison and only when the caller asked for a
 * bounded range.
 *
 * Every preset is institution-agnostic: no lender's calendar, fiscal year or
 * collection cycle is assumed. Weeks start on Monday; quarters are calendar
 * quarters.
 */
final class PortfolioPeriod
{
    /** @var list<string> */
    public const PRESETS = [
        'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month',
        'this_quarter', 'last_quarter', 'this_year', 'last_year', 'all_time', 'custom',
    ];

    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Carbon $previousFrom,
        public readonly Carbon $previousTo,
        public readonly string $key,
        public readonly string $label,
        public readonly bool $isDefault,
        public readonly bool $comparisonMeaningful,
    ) {}

    /**
     * @param  string|null  $range  one of self::PRESETS; null falls back to the default window
     * @param  string|null  $from  ISO date, required when $range is 'custom'
     * @param  string|null  $to  ISO date, required when $range is 'custom'
     */
    public static function resolve(?string $range, ?string $from = null, ?string $to = null, ?Carbon $now = null): self
    {
        $now ??= Carbon::now();
        $today = $now->copy()->startOfDay();

        $isDefault = $range === null || $range === '';
        $key = $isDefault ? 'this_month' : $range;

        if (! in_array($key, self::PRESETS, true)) {
            throw new InvalidArgumentException("Unknown date range [{$key}].");
        }

        [$start, $end, $label, $comparable] = match ($key) {
            'today' => [$today->copy(), $today->copy()->endOfDay(), 'Today', true],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()->endOfDay(), 'Yesterday', true],
            'this_week' => [$today->copy()->startOfWeek(Carbon::MONDAY), $today->copy()->endOfDay(), 'This week', true],
            'last_week' => [
                $today->copy()->subWeek()->startOfWeek(Carbon::MONDAY),
                $today->copy()->subWeek()->endOfWeek(Carbon::SUNDAY),
                'Last week', true,
            ],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay(), 'This month', true],
            'last_month' => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
                'Last month', true,
            ],
            'this_quarter' => [$today->copy()->startOfQuarter(), $today->copy()->endOfDay(), 'This quarter', true],
            'last_quarter' => [
                $today->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $today->copy()->subQuarterNoOverflow()->endOfQuarter(),
                'Last quarter', true,
            ],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfDay(), 'This year', true],
            'last_year' => [
                $today->copy()->subYearNoOverflow()->startOfYear(),
                $today->copy()->subYearNoOverflow()->endOfYear(),
                'Last year', true,
            ],
            'all_time' => [Carbon::create(2000, 1, 1)->startOfDay(), $today->copy()->endOfDay(), 'All time', false],
            'custom' => self::custom($from, $to),
        };

        // Equal-length preceding window, in whole days, ending the day before
        // this one starts. Both windows are day-aligned, so day arithmetic is
        // exact and month lengths never skew the comparison.
        $spanDays = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $previousTo = $start->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($spanDays - 1)->startOfDay();

        return new self(
            from: $start,
            to: $end,
            previousFrom: $previousFrom,
            previousTo: $previousTo,
            key: $key,
            label: $key === 'custom'
                ? $start->toFormattedDateString().' – '.$end->toFormattedDateString()
                : $label,
            isDefault: $isDefault,
            comparisonMeaningful: $comparable,
        );
    }

    /**
     * The instant stock figures are measured at — the end of the window.
     */
    public function asOf(): Carbon
    {
        return $this->to;
    }

    /**
     * The instant the previous window's stock figures are measured at.
     */
    public function previousAsOf(): Carbon
    {
        return $this->previousTo;
    }

    /**
     * Bucket size for the disbursement-vs-collection series, chosen from the
     * span so the chart never renders hundreds of points or fewer than a
     * handful.
     *
     * @return 'day'|'week'|'month'
     */
    public function granularity(): string
    {
        $days = $this->from->diffInDays($this->to) + 1;

        return match (true) {
            $days <= 45 => 'day',
            $days <= 182 => 'week',
            default => 'month',
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string, 3: bool}
     */
    private static function custom(?string $from, ?string $to): array
    {
        if ($from === null || $to === null || $from === '' || $to === '') {
            throw new InvalidArgumentException('A custom range needs both a start and an end date.');
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The start of a custom range cannot be after its end.');
        }

        return [$start, $end, 'Custom range', true];
    }
}
