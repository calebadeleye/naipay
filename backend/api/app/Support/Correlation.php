<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Holds the correlation ID for the current request or queued job.
 *
 * Kept as process state rather than passed through every call signature: audit
 * writes and ledger postings happen deep inside domain services that have no
 * business knowing about HTTP.
 */
final class Correlation
{
    private static ?string $id = null;

    public static function set(string $id): void
    {
        self::$id = $id;
    }

    /**
     * Generates an ID on first read so background work started outside a
     * request (scheduler, tinker, tests) is still traceable.
     */
    public static function id(): string
    {
        return self::$id ??= (string) Str::uuid();
    }

    public static function idOrNull(): ?string
    {
        return self::$id;
    }

    public static function flush(): void
    {
        self::$id = null;
    }
}
