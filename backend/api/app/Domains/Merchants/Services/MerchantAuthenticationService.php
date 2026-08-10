<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Services;

use App\Domains\Identity\Exceptions\AuthenticationFailedException;
use App\Domains\Identity\Support\RequestContext;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signs merchants in and out of the self-service portal.
 *
 * A deliberately narrower version of AuthenticationService, in the same way
 * InvestorAuthenticationService is: no two-factor challenge and no
 * login-attempt log. Lockout and the timing-safe dummy hash check are kept —
 * the account still holds a password worth protecting, and it can now do
 * more than an investor's read-only session (apply for a loan, upload a
 * document, edit a profile).
 */
final class MerchantAuthenticationService
{
    private const TOKEN_NAME = 'naipay-merchant';

    /**
     * @throws AuthenticationFailedException
     */
    public function attempt(string $email, string $password, RequestContext $context): array
    {
        $merchant = Merchant::findForAuthentication($email);

        if ($merchant === null) {
            // Burns the same time a genuine comparison would, so response
            // timing cannot be used to enumerate valid merchant emails.
            Hash::check($password, self::dummyHash());

            throw AuthenticationFailedException::invalidCredentials();
        }

        if ($merchant->isLocked()) {
            throw AuthenticationFailedException::accountLocked(
                max(1, (int) ceil(now()->diffInSeconds($merchant->locked_until, absolute: true) / 60))
            );
        }

        if ($merchant->password === null) {
            // Still runs a dummy comparison first, for the same timing reason
            // as the "not found" branch above — a null password must not
            // resolve faster than a real one.
            Hash::check($password, self::dummyHash());

            throw AuthenticationFailedException::accountInactive(
                'This account has not been activated yet. Check your email for an activation link, or request a new one.'
            );
        }

        if (! Hash::check($password, $merchant->password)) {
            $this->registerFailure($merchant);

            throw AuthenticationFailedException::invalidCredentials();
        }

        if (! $merchant->canAuthenticate()) {
            throw AuthenticationFailedException::accountInactive(
                $merchant->merchant_status->refusalReason() ?? 'This account cannot sign in.'
            );
        }

        $this->clearFailures($merchant);

        return [
            'merchant' => $merchant,
            'token' => $this->issueToken($merchant, $context),
        ];
    }

    public function signOut(Merchant $merchant): void
    {
        $token = $merchant->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        $merchant->tokens()->delete();
    }

    /**
     * Cuts every live session — used on password reset/change and by staff
     * suspending an account.
     */
    public function signOutAllSessions(Merchant $merchant): void
    {
        $merchant->tokens()->delete();
    }

    private function issueToken(Merchant $merchant, RequestContext $context): string
    {
        $lifetimeMinutes = (int) config('naipay.security.token_lifetime_minutes', 480);

        $newToken = $merchant->createToken(
            self::TOKEN_NAME,
            ['portal:self'],
            now()->addMinutes($lifetimeMinutes),
        );

        $newToken->accessToken->forceFill([
            'device_name' => $context->deviceName,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'last_activity_at' => now(),
        ])->save();

        $merchant->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $context->ipAddress,
        ])->save();

        return $newToken->plainTextToken;
    }

    private function registerFailure(Merchant $merchant): void
    {
        $maxAttempts = (int) config('naipay.security.max_login_attempts', 5);
        $lockoutMinutes = (int) config('naipay.security.lockout_minutes', 30);

        $attempts = $merchant->failed_login_attempts + 1;

        $merchant->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockoutMinutes) : null,
        ])->save();
    }

    private function clearFailures(Merchant $merchant): void
    {
        if ($merchant->failed_login_attempts === 0 && $merchant->locked_until === null) {
            return;
        }

        $merchant->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('naipay-merchant-timing-equalisation-'.Str::random(8));
    }
}
