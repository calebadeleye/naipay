<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * Deterministic, keyed hash used to search encrypted columns.
 *
 * Laravel's encryption is randomised — the same BVN encrypts to a different
 * ciphertext every time — which is correct for confidentiality but makes
 * "is this BVN already registered?" unanswerable with a WHERE clause.
 *
 * The standard answer is a blind index: alongside the encrypted value, store a
 * keyed hash of it. Equality lookups and uniqueness constraints run against the
 * hash; the plaintext is only ever recovered by decrypting, and only for staff
 * holding the permission to see it.
 *
 * HMAC-SHA256 with the application key, not a bare hash. A BVN is 11 digits —
 * the whole space is 10^11, which a plain SHA-256 rainbow table would exhaust
 * in minutes. Keying it means an attacker with the database but not APP_KEY
 * cannot reverse the index.
 */
final class BlindIndex
{
    /**
     * Produces the index value for a piece of sensitive data.
     *
     * @param  string  $domain  Separates namespaces so the same digits indexed as a
     *                          BVN and as a NIN do not produce the same hash.
     */
    public static function hash(string $value, string $domain): string
    {
        $normalised = self::normalise($value);

        if ($normalised === '') {
            throw new RuntimeException('Cannot index an empty value.');
        }

        return hash_hmac('sha256', $domain.':'.$normalised, self::key());
    }

    /**
     * @return string|null Null for a null or blank input, so callers can pass
     *                     optional fields straight through.
     */
    public static function hashOrNull(?string $value, string $domain): ?string
    {
        if ($value === null || self::normalise($value) === '') {
            return null;
        }

        return self::hash($value, $domain);
    }

    /**
     * Strips formatting so "22 123 456 714" and "22123456714" index alike.
     */
    private static function normalise(string $value): string
    {
        return preg_replace('/\s+/', '', trim($value)) ?? '';
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY must be set before sensitive data can be indexed.');
        }

        // Laravel stores the key base64-encoded; the raw bytes are what should
        // be fed to HMAC.
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }
}
