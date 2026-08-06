<?php

declare(strict_types=1);

namespace App\Domains\Investors\Services;

use App\Domains\Identity\Exceptions\AuthenticationFailedException;
use App\Domains\Identity\Support\RequestContext;
use App\Domains\Investors\Models\Investor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signs investors in and out.
 *
 * A deliberately narrower version of AuthenticationService: no two-factor
 * challenge and no login-attempt log, since an investor's session grants
 * nothing beyond viewing one read-only dashboard. Lockout and the
 * timing-safe dummy hash check are kept — the account still holds a
 * password worth protecting.
 */
final class InvestorAuthenticationService
{
    private const TOKEN_NAME = 'naipay-investor';

    /**
     * @throws AuthenticationFailedException
     */
    public function attempt(string $email, string $password, RequestContext $context): array
    {
        $investor = Investor::findForAuthentication($email);

        if ($investor === null) {
            // Burns the same time a genuine comparison would, so response
            // timing cannot be used to enumerate valid investor emails.
            Hash::check($password, self::dummyHash());

            throw AuthenticationFailedException::invalidCredentials();
        }

        if ($investor->isLocked()) {
            throw AuthenticationFailedException::accountLocked(
                max(1, (int) ceil(now()->diffInSeconds($investor->locked_until, absolute: true) / 60))
            );
        }

        if (! Hash::check($password, $investor->password)) {
            $this->registerFailure($investor);

            throw AuthenticationFailedException::invalidCredentials();
        }

        if (! $investor->status->canAuthenticate()) {
            throw AuthenticationFailedException::accountInactive(
                $investor->status->refusalReason() ?? 'This account cannot sign in.'
            );
        }

        $this->clearFailures($investor);

        return [
            'investor' => $investor,
            'token' => $this->issueToken($investor, $context),
        ];
    }

    public function signOut(Investor $investor): void
    {
        $token = $investor->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        $investor->tokens()->delete();
    }

    private function issueToken(Investor $investor, RequestContext $context): string
    {
        $lifetimeMinutes = (int) config('naipay.security.token_lifetime_minutes', 480);

        $newToken = $investor->createToken(
            self::TOKEN_NAME,
            ['dashboard:view'],
            now()->addMinutes($lifetimeMinutes),
        );

        $newToken->accessToken->forceFill([
            'device_name' => $context->deviceName,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'last_activity_at' => now(),
        ])->save();

        $investor->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $context->ipAddress,
        ])->save();

        return $newToken->plainTextToken;
    }

    private function registerFailure(Investor $investor): void
    {
        $maxAttempts = (int) config('naipay.security.max_login_attempts', 5);
        $lockoutMinutes = (int) config('naipay.security.lockout_minutes', 30);

        $attempts = $investor->failed_login_attempts + 1;

        $investor->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockoutMinutes) : null,
        ])->save();
    }

    private function clearFailures(Investor $investor): void
    {
        if ($investor->failed_login_attempts === 0 && $investor->locked_until === null) {
            return;
        }

        $investor->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('naipay-investor-timing-equalisation-'.Str::random(8));
    }
}
