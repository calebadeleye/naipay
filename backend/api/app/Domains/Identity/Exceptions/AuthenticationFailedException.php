<?php

declare(strict_types=1);

namespace App\Domains\Identity\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * A refused sign-in.
 *
 * The message is deliberately uniform for a wrong password and an unknown
 * identifier: distinguishing them would let an attacker enumerate which staff
 * accounts exist. Status-based refusals (suspended, disabled) do say so,
 * because the operator has already proved they hold the password.
 */
final class AuthenticationFailedException extends DomainException
{
    public static function invalidCredentials(): self
    {
        return new self(
            'The credentials provided are incorrect.',
            [],
            HttpStatus::HTTP_UNAUTHORIZED,
        );
    }

    public static function accountLocked(int $minutesRemaining): self
    {
        return new self(
            "This account is locked after too many failed sign-in attempts. Try again in {$minutesRemaining} minute(s), or contact your administrator.",
            [],
            HttpStatus::HTTP_LOCKED,
        );
    }

    public static function accountInactive(string $reason): self
    {
        return new self($reason, [], HttpStatus::HTTP_FORBIDDEN);
    }

    public static function invalidTwoFactorCode(): self
    {
        return new self(
            'That verification code is not valid.',
            ['code' => ['The verification code is incorrect or has expired.']],
            HttpStatus::HTTP_UNAUTHORIZED,
        );
    }

    public static function challengeExpired(): self
    {
        return new self(
            'This sign-in attempt has expired. Please sign in again.',
            [],
            HttpStatus::HTTP_UNAUTHORIZED,
        );
    }
}
