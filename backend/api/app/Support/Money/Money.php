<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Held as an integer count of minor units (kobo for NGN). Floats are never used
 * anywhere in the lifecycle: `0.1 + 0.2 !== 0.3` is not an acceptable property
 * for a loan balance, and a rounding drift of one kobo per instalment compounds
 * into a schedule that will not close out.
 *
 * The database counterpart is DECIMAL(20,2); conversion happens in MoneyCast.
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private const MINOR_UNITS_PER_MAJOR = 100;

    private function __construct(
        private readonly int $minorUnits,
        private readonly string $currency,
    ) {}

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    public static function zero(string $currency = 'NGN'): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    public static function fromMinorUnits(int $minorUnits, string $currency = 'NGN'): self
    {
        return new self($minorUnits, self::normaliseCurrency($currency));
    }

    /**
     * Parses a decimal string such as "125000.50".
     *
     * Accepts int and float inputs for ergonomics at the system edges (config
     * files, seeders, JSON request bodies), but routes them through a string
     * representation so the value is never subject to binary rounding.
     */
    public static function fromDecimal(string|int|float $amount, string $currency = 'NGN'): self
    {
        $normalised = self::stringify($amount);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalised, $matches) !== 1) {
            throw new InvalidArgumentException("Value [{$normalised}] is not a valid monetary amount.");
        }

        [, $sign, $major, $fraction] = $matches + [3 => ''];

        // Reject rather than silently truncate: a caller passing 100.005 has a
        // precision bug, and rounding it away would hide a real discrepancy.
        if (strlen($fraction) > self::SCALE && rtrim(substr($fraction, self::SCALE), '0') !== '') {
            throw new InvalidArgumentException(
                "Value [{$normalised}] carries more precision than ".self::SCALE.' decimal places.'
            );
        }

        $minorFraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
        $minorUnits = (int) $major * self::MINOR_UNITS_PER_MAJOR + (int) $minorFraction;

        return new self($sign === '-' ? -$minorUnits : $minorUnits, self::normaliseCurrency($currency));
    }

    public function minorUnits(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The DECIMAL(20,2) representation written to the database.
     */
    public function toDecimalString(): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        $major = intdiv($absolute, self::MINOR_UNITS_PER_MAJOR);
        $minor = $absolute % self::MINOR_UNITS_PER_MAJOR;

        return sprintf('%s%d.%02d', $sign, $major, $minor);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiplies by a rate (interest, fee percentage) using integer arithmetic
     * with explicit half-up rounding on the final kobo.
     */
    public function multiplyBy(string|int|float $multiplier, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        $scaled = self::scaleFactor(self::stringify($multiplier));

        $product = $this->minorUnits * $scaled['numerator'];

        return new self(self::divideRounded($product, $scaled['denominator'], $rounding), $this->currency);
    }

    public function divideBy(int $divisor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Cannot divide a monetary amount by zero.');
        }

        return new self(self::divideRounded($this->minorUnits, $divisor, $rounding), $this->currency);
    }

    /**
     * Splits an amount into `$parts` pieces that sum back exactly to the
     * original. Remainder kobo are distributed one each across the leading
     * parts, so an instalment schedule never loses or invents a kobo.
     *
     * @return array<int, self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate a monetary amount into fewer than one part.');
        }

        $base = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits - ($base * $parts);
        $direction = $remainder < 0 ? -1 : 1;
        $remainder = abs($remainder);

        $slices = [];

        for ($index = 0; $index < $parts; $index++) {
            $extra = $index < $remainder ? $direction : 0;
            $slices[] = new self($base + $extra, $this->currency);
        }

        return $slices;
    }

    /**
     * Caps an allocation at the amount actually outstanding — the workhorse of
     * repayment allocation, where each bucket takes the lesser of what is due
     * and what remains of the payment.
     */
    public function cappedAt(self $ceiling): self
    {
        $this->assertSameCurrency($ceiling);

        return $this->greaterThan($ceiling) ? $ceiling : $this;
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits;
    }

    /**
     * @param  array<int, self>  $amounts
     */
    public static function sum(array $amounts, string $currency = 'NGN'): self
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Display form for receipts, statements and exports.
     */
    public function format(bool $withSymbol = true): string
    {
        $sign = $this->minorUnits < 0 ? '-' : '';
        $absolute = abs($this->minorUnits);

        $major = number_format(intdiv($absolute, self::MINOR_UNITS_PER_MAJOR));
        $minor = str_pad((string) ($absolute % self::MINOR_UNITS_PER_MAJOR), self::SCALE, '0', STR_PAD_LEFT);

        $symbol = $withSymbol ? Currency::symbolFor($this->currency) : '';

        return "{$sign}{$symbol}{$major}.{$minor}";
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDecimalString(),
            'minor_units' => $this->minorUnits,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Value [{$currency}] is not a valid ISO 4217 currency code.");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine amounts in {$this->currency} and {$other->currency}."
            );
        }
    }

    /**
     * Renders a scalar as a plain decimal string. `%.10F` avoids the scientific
     * notation PHP otherwise produces for small or large floats, which the
     * decimal parser would reject.
     */
    private static function stringify(string|int|float $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    /**
     * Expresses a decimal multiplier as an exact integer fraction, keeping rate
     * arithmetic free of floating point.
     *
     * @return array{numerator: int, denominator: int}
     */
    private static function scaleFactor(string $multiplier): array
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $multiplier, $matches) !== 1) {
            throw new InvalidArgumentException("Value [{$multiplier}] is not a valid multiplier.");
        }

        [, $sign, $major, $fraction] = $matches + [3 => ''];

        $denominator = 10 ** strlen($fraction);
        $numerator = (int) ($major.$fraction);

        return [
            'numerator' => $sign === '-' ? -$numerator : $numerator,
            'denominator' => $denominator,
        ];
    }

    /**
     * Integer division with an explicit rounding policy, correct for negative
     * numerators (intdiv truncates toward zero, which would bias reversals).
     */
    private static function divideRounded(int $numerator, int $denominator, RoundingMode $rounding): int
    {
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator - ($quotient * $denominator);

        if ($remainder === 0) {
            return $quotient;
        }

        $negative = $remainder < 0;
        $absRemainder = abs($remainder);

        $roundAway = match ($rounding) {
            RoundingMode::Down => false,
            RoundingMode::Up => true,
            RoundingMode::HalfUp => $absRemainder * 2 >= $denominator,
            RoundingMode::HalfDown => $absRemainder * 2 > $denominator,
        };

        if (! $roundAway) {
            return $quotient;
        }

        return $negative ? $quotient - 1 : $quotient + 1;
    }
}
