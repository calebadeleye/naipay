<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Enums;

/**
 * How often a merchant repays.
 *
 * This is the axis Naipay's products are organised around: a daily-collection
 * loan and a monthly one are different products with different pricing,
 * because money arriving every working day is a materially different risk from
 * money arriving once a month.
 */
enum RepaymentFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * The unit a tenor is counted in for this frequency.
     *
     * A daily loan's tenor is a number of payments (working days), a weekly
     * loan's is a number of weeks, a monthly loan's a number of months.
     */
    public function tenorUnit(): TenorUnit
    {
        return match ($this) {
            self::Daily => TenorUnit::Days,
            self::Weekly => TenorUnit::Weeks,
            self::Monthly => TenorUnit::Months,
        };
    }

    /**
     * Whether instalment dates must fall on working days.
     *
     * Only daily collection is affected: merchants are not expected to pay at
     * the weekend, and a schedule that lands instalments on a Saturday would
     * mark them overdue on the Monday for no reason.
     */
    public function skipsWeekends(): bool
    {
        return $this === self::Daily;
    }

    public function noun(): string
    {
        return match ($this) {
            self::Daily => 'working day',
            self::Weekly => 'week',
            self::Monthly => 'month',
        };
    }
}
