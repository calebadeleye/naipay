<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips credentials and identity numbers from log context before anything is
 * written to disk or shipped to an aggregator.
 *
 * This is a backstop, not a licence to log carelessly — call sites should not
 * be putting a BVN into a log line in the first place. But logs are long-lived,
 * widely readable and easy to leak, and "no sensitive data in application logs"
 * is a hard requirement, so the guarantee is enforced centrally rather than
 * relying on every future contributor remembering.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    private const REDACTED = '[redacted]';

    /**
     * Guards against a self-referencing context array turning redaction into an
     * infinite descent.
     */
    private const MAX_DEPTH = 12;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::REDACTED];
        }

        $sensitiveKeys = $this->sensitiveKeys();
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key, $sensitiveKeys)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redact($value, $depth + 1)
                : $this->redactScalar($value);
        }

        return $redacted;
    }

    /**
     * Catches values that look like a bearer token or an 11-digit Nigerian BVN
     * or NIN even when the key gave no indication.
     */
    private function redactScalar(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^Bearer\s+\S+$/i', $value) === 1) {
            return self::REDACTED;
        }

        if (preg_match('/^\d{11}$/', $value) === 1) {
            return self::REDACTED;
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $sensitiveKeys
     */
    private function isSensitive(string $key, array $sensitiveKeys): bool
    {
        $normalised = str_replace(['-', ' '], '_', mb_strtolower($key));

        foreach ($sensitiveKeys as $sensitive) {
            if (str_contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function sensitiveKeys(): array
    {
        /** @var array<int, string> $keys */
        $keys = config('naipay.security.redacted_keys', []);

        return array_map(
            static fn (string $key): string => str_replace(['-', ' '], '_', mb_strtolower($key)),
            $keys,
        );
    }
}
