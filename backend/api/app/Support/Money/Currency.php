<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Currency metadata. Naipay operates in Naira; the indirection exists so a
 * second currency does not require touching every call site.
 */
final class Currency
{
    public const DEFAULT = 'NGN';

    private const SYMBOLS = [
        'NGN' => '₦',
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€',
    ];

    public static function symbolFor(string $code): string
    {
        return self::SYMBOLS[strtoupper($code)] ?? strtoupper($code).' ';
    }

    public static function isSupported(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::SYMBOLS);
    }
}
