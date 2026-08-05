<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Enums;

enum TenorUnit: string
{
    case Days = 'days';
    case Weeks = 'weeks';
    case Months = 'months';

    public function label(): string
    {
        return match ($this) {
            self::Days => 'Days',
            self::Weeks => 'Weeks',
            self::Months => 'Months',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Days => 'day',
            self::Weeks => 'week',
            self::Months => 'month',
        };
    }
}
