<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Support\Exceptions\DomainException;
use Illuminate\Database\ConnectionInterface;

/**
 * Allocates the 10-digit account numbers merchants are given.
 *
 * Random rather than sequential, deliberately. A sequential account number
 * discloses how many merchants Naipay has and roughly when each was onboarded,
 * and — more practically — makes neighbouring accounts guessable, which matters
 * once these numbers appear on transfer instructions and receipts.
 *
 * Ten digits matches the Nigerian NUBAN length operators and merchants already
 * recognise, so a Naipay account number looks like the bank account numbers
 * they use every day.
 */
final class AccountNumberGenerator
{
    private const LENGTH = 10;

    /**
     * With 10^10 possible numbers, a collision is vanishingly unlikely at any
     * realistic book size, but "unlikely" is not "impossible" — so generation
     * retries, and the unique key on the column is what actually guarantees it.
     */
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @param  string  $table  Table holding the numbers, for the collision check.
     * @param  string  $column  Column holding the numbers.
     */
    public function next(string $table = 'merchant_accounts', string $column = 'account_number'): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $this->generate();

            $taken = $this->connection->table($table)->where($column, $candidate)->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        throw new DomainException(
            'Could not allocate a unique account number. Please try again.',
        );
    }

    /**
     * Produces a 10-digit string.
     *
     * `random_int` rather than `rand`: these numbers are quoted on payment
     * instructions, and a predictable sequence would let someone enumerate
     * valid accounts. Leading zeros are kept — the value is an identifier, not
     * a quantity, and is always handled as a string.
     */
    private function generate(): string
    {
        $digits = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }
}
