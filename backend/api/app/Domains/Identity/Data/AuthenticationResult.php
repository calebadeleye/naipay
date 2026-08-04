<?php

declare(strict_types=1);

namespace App\Domains\Identity\Data;

use App\Domains\Identity\Models\Staff;

/**
 * Outcome of a successful credential check.
 *
 * Either the operator is signed in and holds a token, or they have cleared the
 * first factor and must answer a two-factor challenge. Failures are raised as
 * exceptions rather than represented here.
 */
final class AuthenticationResult
{
    private function __construct(
        public readonly Staff $staff,
        public readonly ?string $token,
        public readonly ?string $challengeToken,
        public readonly ?int $challengeExpiresInSeconds,
    ) {}

    public static function authenticated(Staff $staff, string $token): self
    {
        return new self($staff, $token, null, null);
    }

    public static function twoFactorRequired(
        Staff $staff,
        string $challengeToken,
        int $expiresInSeconds,
    ): self {
        return new self($staff, null, $challengeToken, $expiresInSeconds);
    }

    public function requiresTwoFactor(): bool
    {
        return $this->challengeToken !== null;
    }
}
