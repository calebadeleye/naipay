<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Rounding policy applied when an exact monetary result falls between kobo.
 *
 * Interest accrual rounds half-up by convention; fee waivers and write-downs
 * round in the merchant's favour. Every call site states its choice rather than
 * inheriting a global default.
 */
enum RoundingMode: string
{
    case HalfUp = 'half_up';
    case HalfDown = 'half_down';
    case Up = 'up';
    case Down = 'down';
}
