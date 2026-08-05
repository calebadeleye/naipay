<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Services;

use Illuminate\Support\Carbon;

/**
 * Working days for repayment scheduling.
 *
 * Merchants on daily collection are not expected to pay at the weekend, so an
 * instalment must never fall on a Saturday or Sunday. Without this, a schedule
 * would mark a merchant overdue on Monday for a Saturday instalment they were
 * never asked to make, and the delinquency reports would be wrong every week.
 *
 * Public holidays are supported through configuration. They are deliberately
 * not hard-coded: Nigerian public holidays move with the lunar calendar and are
 * sometimes announced only days ahead, so the list has to be editable without a
 * deployment.
 */
final class BusinessDayCalendar
{
    /**
     * @var array<int, string>|null Dates as Y-m-d, or null to read config.
     */
    private ?array $holidays;

    /**
     * Holidays may be injected, which keeps the calendar usable from a pure
     * unit test with no container, and lets a report ask "what would the
     * schedule have been under last year's holidays?".
     *
     * @param  array<int, string>|null  $holidays
     */
    public function __construct(?array $holidays = null)
    {
        $this->holidays = $holidays;
    }

    /**
     * Whether a date is a working day.
     */
    public function isBusinessDay(Carbon $date): bool
    {
        if ($date->isSaturday() || $date->isSunday()) {
            return false;
        }

        return ! $this->isHoliday($date);
    }

    /**
     * The given date if it is a working day, otherwise the next one.
     *
     * Instalments move forward rather than back: pulling a payment earlier
     * than scheduled would demand money before the merchant expected to owe it.
     */
    public function onOrAfter(Carbon $date): Carbon
    {
        $candidate = $date->copy()->startOfDay();

        // Bounded so a misconfigured holiday list cannot spin forever.
        for ($attempts = 0; $attempts < 400; $attempts++) {
            if ($this->isBusinessDay($candidate)) {
                return $candidate;
            }

            $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * Advances by a number of working days.
     *
     * `addBusinessDays(1)` from a Friday lands on the following Monday.
     */
    public function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $candidate = $date->copy()->startOfDay();

        for ($moved = 0; $moved < $days; $moved++) {
            $candidate->addDay();
            $candidate = $this->onOrAfter($candidate);
        }

        return $candidate;
    }

    /**
     * Counts working days between two dates, excluding the start and including
     * the end.
     */
    public function businessDaysBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $count = 0;
        $cursor = $from->copy()->startOfDay()->addDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            if ($this->isBusinessDay($cursor)) {
                $count++;
            }

            $cursor->addDay();
        }

        return $count;
    }

    /**
     * Public holidays, as configured.
     *
     * @return array<int, string> Dates as Y-m-d.
     */
    public function holidays(): array
    {
        if ($this->holidays !== null) {
            return $this->holidays;
        }

        // Read lazily rather than in the constructor so a holiday added to
        // configuration takes effect without restarting the workers.
        /** @var array<int, string> $holidays */
        $holidays = function_exists('config') && app()->bound('config')
            ? config('naipay.loans.public_holidays', [])
            : [];

        return $holidays;
    }

    private function isHoliday(Carbon $date): bool
    {
        return in_array($date->toDateString(), $this->holidays(), true);
    }
}
