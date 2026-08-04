<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Masks sensitive identifiers for display.
 *
 * Shows enough for an operator to confirm they are looking at the right record
 * without exposing the value. A BVN reads as 22*******14.
 *
 * Masking happens on the way out of the API, not in the browser: sending the
 * full value and hiding it in the client would leave it in the response body,
 * the browser cache and any proxy in between.
 */
final class Mask
{
    /**
     * Leaves the first two and last two characters visible.
     */
    public static function identityNumber(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = mb_strlen($value);

        // Too short to mask meaningfully — hide it entirely rather than
        // revealing most of it.
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2).str_repeat('*', $length - 4).mb_substr($value, -2);
    }

    /**
     * Shows the last four digits of a bank account number: ******7890.
     */
    public static function accountNumber(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = mb_strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    /**
     * Masks the local part of an email: a****a@naitalk.com.
     */
    public static function email(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '@')) {
            return $value;
        }

        [$local, $domain] = explode('@', $value, 2);

        $length = mb_strlen($local);

        if ($length <= 2) {
            return str_repeat('*', $length).'@'.$domain;
        }

        return mb_substr($local, 0, 1).str_repeat('*', $length - 2).mb_substr($local, -1).'@'.$domain;
    }

    /**
     * Masks a phone number, keeping the last three digits: 0803****567.
     */
    public static function phone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = mb_strlen($value);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 4).str_repeat('*', $length - 7).mb_substr($value, -3);
    }
}
