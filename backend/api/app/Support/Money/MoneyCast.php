<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a DECIMAL(20,2) column to a Money value object.
 *
 * Applied to every monetary column so no domain code ever handles a raw string
 * or float balance. The currency defaults to NGN but can be bound to a sibling
 * column: `Money::class.':currency'`.
 *
 * @implements CastsAttributes<Money, Money|string|int|float|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly ?string $currencyColumn = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromDecimal((string) $value, $this->resolveCurrency($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            $currency = $this->resolveCurrency($attributes);

            if ($value->currency() !== $currency) {
                throw new InvalidArgumentException(
                    "Cannot store a {$value->currency()} amount in a {$currency} record."
                );
            }

            return [$key => $value->toDecimalString()];
        }

        return [$key => Money::fromDecimal($value, $this->resolveCurrency($attributes))->toDecimalString()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveCurrency(array $attributes): string
    {
        if ($this->currencyColumn === null) {
            return Currency::DEFAULT;
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : Currency::DEFAULT;
    }
}
