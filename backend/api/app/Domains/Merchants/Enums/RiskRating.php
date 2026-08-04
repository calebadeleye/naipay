<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Enums;

/**
 * Merchant risk classification, set during credit assessment.
 */
enum RiskRating: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::VeryHigh => 'Very high',
        };
    }

    /**
     * Ordering for reports and thresholds. Higher means riskier.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Moderate => 2,
            self::High => 3,
            self::VeryHigh => 4,
        };
    }
}
