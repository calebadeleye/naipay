<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Support\Exceptions\DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Allocates the human-readable references staff use to identify records:
 * NPM-000001, NPL-2026-000001, NPR-2026-000001 and so on.
 *
 * Concurrency
 * -----------
 * Allocation is a single atomic statement:
 *
 *     INSERT ... ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)
 *
 * MySQL takes an exclusive lock on the sequence row for the duration of the
 * enclosing transaction, so two requests allocating the same sequence
 * serialise. LAST_INSERT_ID is per-connection, so each caller reads back its
 * own value with no race. Two records can therefore never receive the same
 * reference, which is the property that actually matters.
 *
 * Gaps are possible and acceptable: if the surrounding transaction rolls back,
 * the counter rolls back with it, and a merchant who was never created leaves
 * no reference behind. Nothing downstream assumes references are contiguous.
 *
 * The trade-off is that the sequence row stays locked until the outer
 * transaction commits, so references should be allocated late — once the work
 * is certain to succeed — rather than at the top of a long transaction.
 */
final class ReferenceGenerator
{
    /**
     * Sequences that run continuously use this in place of a year, so one
     * unique key serves both resetting and non-resetting sequences.
     */
    private const CONTINUOUS_PERIOD = 'ALL';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Produces the next reference for a configured sequence.
     *
     * @param  string  $sequence  A key under `naipay.references`, e.g. 'merchant'.
     * @param  Carbon|null  $issuedAt  Determines the year for annually-resetting
     *                                 sequences; defaults to now.
     */
    public function next(string $sequence, ?Carbon $issuedAt = null): string
    {
        $definition = $this->definition($sequence);
        $issuedAt ??= Carbon::now();

        $pattern = $definition['pattern'];
        $padding = (int) ($definition['padding'] ?? 6);

        $resetsAnnually = str_contains($pattern, '{YEAR}');
        $year = $issuedAt->format('Y');
        $period = $resetsAnnually ? $year : self::CONTINUOUS_PERIOD;

        $value = $this->allocate($sequence, $period);

        return str_replace(
            ['{YEAR}', '{SEQ}'],
            [$year, str_pad((string) $value, $padding, '0', STR_PAD_LEFT)],
            $pattern,
        );
    }

    /**
     * Allocates a block of consecutive references in one go.
     *
     * Used where many records are created together — importing a branch's
     * merchant book, for instance — so the sequence row is locked once rather
     * than once per record.
     *
     * @return array<int, string>
     */
    public function nextBatch(string $sequence, int $count, ?Carbon $issuedAt = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A reference batch must contain at least one reference.');
        }

        $definition = $this->definition($sequence);
        $issuedAt ??= Carbon::now();

        $pattern = $definition['pattern'];
        $padding = (int) ($definition['padding'] ?? 6);

        $resetsAnnually = str_contains($pattern, '{YEAR}');
        $year = $issuedAt->format('Y');
        $period = $resetsAnnually ? $year : self::CONTINUOUS_PERIOD;

        // Returns the highest value in the block; the block is the `$count`
        // values ending there.
        $last = $this->allocate($sequence, $period, $count);
        $first = $last - $count + 1;

        $references = [];

        for ($value = $first; $value <= $last; $value++) {
            $references[] = str_replace(
                ['{YEAR}', '{SEQ}'],
                [$year, str_pad((string) $value, $padding, '0', STR_PAD_LEFT)],
                $pattern,
            );
        }

        return $references;
    }

    /**
     * Atomically advances the counter and returns the value reached.
     *
     * Both branches funnel their result through LAST_INSERT_ID(expr), which
     * sets the session value and returns it. That matters: the counter must
     * never be read back with a separate SELECT, because outside an explicit
     * transaction the statement autocommits and releases its row lock, leaving
     * a window in which another connection could increment past us and hand
     * both callers the same reference.
     *
     * @return int The value reached — for a batch, the highest in the block.
     */
    private function allocate(string $sequence, string $period, int $increment = 1): int
    {
        $now = Carbon::now()->toDateTimeString();

        $this->connection->statement(
            'INSERT INTO reference_sequences (name, period, current_value, created_at, updated_at)
             VALUES (?, ?, LAST_INSERT_ID(?), ?, ?)
             ON DUPLICATE KEY UPDATE
                current_value = LAST_INSERT_ID(current_value + ?),
                updated_at = ?',
            [$sequence, $period, $increment, $now, $now, $increment, $now],
        );

        $result = $this->connection->selectOne('SELECT LAST_INSERT_ID() AS value');

        $value = (int) ($result->value ?? 0);

        if ($value < 1) {
            throw new DomainException("Failed to allocate a reference for sequence [{$sequence}].");
        }

        return $value;
    }

    /**
     * @return array{pattern: string, padding?: int}
     */
    private function definition(string $sequence): array
    {
        /** @var array{pattern: string, padding?: int}|null $definition */
        $definition = config("naipay.references.{$sequence}");

        if ($definition === null || ! isset($definition['pattern'])) {
            throw new InvalidArgumentException(
                "No reference format is configured for sequence [{$sequence}]."
            );
        }

        return $definition;
    }
}
