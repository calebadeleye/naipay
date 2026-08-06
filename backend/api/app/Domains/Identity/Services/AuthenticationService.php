<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Data\AuthenticationResult;
use App\Domains\Identity\Enums\StaffStatus;
use App\Domains\Identity\Exceptions\AuthenticationFailedException;
use App\Domains\Identity\Models\LoginAttempt;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Notifications\StaffSignedInNotification;
use App\Domains\Identity\Support\RequestContext;
use App\Support\Correlation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signs staff in and out.
 *
 * Owns the sequence that has to be right every time: check the account is
 * usable, verify the password without leaking whether the account exists,
 * count failures towards a lockout, issue a two-factor challenge where one is
 * required, and record the attempt either way.
 */
final class AuthenticationService
{
    private const TOKEN_NAME = 'naipay-admin';

    /**
     * How long the operator has to answer a two-factor challenge.
     *
     * Long enough to open an authenticator app and read a code; short enough
     * that a challenge token left on a shared workstation is not useful.
     */
    private const CHALLENGE_TTL_SECONDS = 300;

    private const CHALLENGE_CACHE_PREFIX = 'naipay:2fa-challenge:';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Verifies an identifier and password.
     *
     * @throws AuthenticationFailedException
     */
    public function attempt(string $identifier, string $password, RequestContext $context): AuthenticationResult
    {
        $staff = Staff::findForAuthentication($identifier);

        if ($staff === null) {
            // The password is still hashed against a dummy value so that an
            // unknown identifier takes the same time as a known one — without
            // this, response timing enumerates valid accounts.
            Hash::check($password, self::dummyHash());

            $this->recordAttempt(null, $identifier, false, LoginAttempt::FAILURE_INVALID_CREDENTIALS, $context);

            throw AuthenticationFailedException::invalidCredentials();
        }

        if ($staff->isLocked()) {
            $this->recordAttempt($staff, $identifier, false, LoginAttempt::FAILURE_ACCOUNT_LOCKED, $context);

            throw AuthenticationFailedException::accountLocked(
                max(1, (int) ceil(now()->diffInSeconds($staff->locked_until, absolute: true) / 60))
            );
        }

        if (! Hash::check($password, $staff->password)) {
            $this->registerFailure($staff);
            $this->recordAttempt($staff, $identifier, false, LoginAttempt::FAILURE_INVALID_CREDENTIALS, $context);

            throw AuthenticationFailedException::invalidCredentials();
        }

        // Status is checked only after the password is proven. Telling an
        // unauthenticated caller that an account is suspended would confirm it
        // exists; telling the genuine holder is helpful.
        if (! $staff->status->canAuthenticate()) {
            $this->recordAttempt($staff, $identifier, false, LoginAttempt::FAILURE_ACCOUNT_INACTIVE, $context);

            throw AuthenticationFailedException::accountInactive(
                $staff->status->refusalReason() ?? 'This account cannot sign in.'
            );
        }

        // The password was right, so the failure counter resets regardless of
        // what happens next.
        $this->clearFailures($staff);

        if ($staff->hasTwoFactorEnabled()) {
            return AuthenticationResult::twoFactorRequired(
                $staff,
                $this->issueChallenge($staff, $context),
                self::CHALLENGE_TTL_SECONDS,
            );
        }

        return AuthenticationResult::authenticated(
            $staff,
            $this->issueToken($staff, $context),
        );
    }

    /**
     * Completes a two-factor challenge and issues a token.
     *
     * @throws AuthenticationFailedException
     */
    public function completeTwoFactorChallenge(
        string $challengeToken,
        string $code,
        RequestContext $context,
    ): AuthenticationResult {
        $staff = $this->resolveChallenge($challengeToken);

        if (! $this->twoFactor->verifyChallenge($staff, $code)) {
            // A wrong second factor counts towards lockout too — otherwise an
            // attacker holding the password gets unlimited attempts at the code.
            $this->registerFailure($staff);
            $this->recordAttempt($staff, $staff->email, false, LoginAttempt::FAILURE_INVALID_TWO_FACTOR, $context);

            throw AuthenticationFailedException::invalidTwoFactorCode();
        }

        // Single-use: consumed the moment it succeeds.
        Cache::forget(self::CHALLENGE_CACHE_PREFIX.hash('sha256', $challengeToken));

        $this->clearFailures($staff);

        return AuthenticationResult::authenticated(
            $staff,
            $this->issueToken($staff, $context),
        );
    }

    /**
     * Emails a one-time code as an alternative to an authenticator app, for a
     * pending two-factor challenge.
     *
     * @throws AuthenticationFailedException
     */
    public function sendTwoFactorEmailCode(string $challengeToken): void
    {
        $staff = $this->resolveChallenge($challengeToken);

        $this->twoFactor->sendEmailCode($staff);
    }

    /**
     * Looks up the staff a pending two-factor challenge belongs to.
     *
     * @throws AuthenticationFailedException
     */
    private function resolveChallenge(string $challengeToken): Staff
    {
        $cacheKey = self::CHALLENGE_CACHE_PREFIX.hash('sha256', $challengeToken);

        /** @var array{staff_id: int}|null $payload */
        $payload = Cache::get($cacheKey);

        if ($payload === null) {
            throw AuthenticationFailedException::challengeExpired();
        }

        $staff = Staff::find($payload['staff_id']);

        if ($staff === null || ! $staff->canAuthenticate()) {
            Cache::forget($cacheKey);

            throw AuthenticationFailedException::challengeExpired();
        }

        return $staff;
    }

    /**
     * Proves the caller is still who they say they are, at the point of a
     * sensitive action, independent of the session already being valid.
     *
     * Marks only the token used for this request — every other session an
     * operator holds still requires its own step-up when it reaches one of
     * these operations.
     *
     * @throws AuthenticationFailedException
     */
    public function reauthenticate(
        Staff $staff,
        string $password,
        ?string $twoFactorCode,
        RequestContext $context,
        ?string $operation = null,
    ): void {
        if (! Hash::check($password, $staff->password)) {
            $this->registerFailure($staff);
            $this->recordAttempt($staff, $staff->email, false, LoginAttempt::FAILURE_INVALID_CREDENTIALS, $context);

            throw AuthenticationFailedException::invalidCredentials();
        }

        $twoFactorVerified = false;

        if ($staff->hasTwoFactorEnabled()) {
            $codeRequired = $operation === null || ! $this->twoFactorOptionalFor($operation);

            if ($twoFactorCode !== null) {
                if (! $this->twoFactor->verifyChallenge($staff, $twoFactorCode)) {
                    $this->registerFailure($staff);
                    $this->recordAttempt($staff, $staff->email, false, LoginAttempt::FAILURE_INVALID_TWO_FACTOR, $context);

                    throw AuthenticationFailedException::invalidTwoFactorCode();
                }

                $twoFactorVerified = true;
            } elseif ($codeRequired) {
                $this->registerFailure($staff);
                $this->recordAttempt($staff, $staff->email, false, LoginAttempt::FAILURE_INVALID_TWO_FACTOR, $context);

                throw AuthenticationFailedException::invalidTwoFactorCode();
            }
        }

        $this->clearFailures($staff);

        $token = $staff->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->forceFill([
                'reauthenticated_at' => now(),
                'reauthenticated_with_two_factor' => $twoFactorVerified,
            ])->save();
        }
    }

    /**
     * Whether `$operation` accepts a password-only reauthentication even
     * when the account has two-factor enabled. See
     * naipay.security.reauthentication_two_factor_optional_operations.
     */
    private function twoFactorOptionalFor(string $operation): bool
    {
        return in_array(
            $operation,
            (array) config('naipay.security.reauthentication_two_factor_optional_operations', []),
            true,
        );
    }

    /**
     * Revokes the token used for the current request.
     */
    public function signOut(Staff $staff): void
    {
        $token = $staff->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        // No current token implies a non-token guard; revoking everything is
        // the safe interpretation of "sign me out".
        $staff->tokens()->delete();
    }

    /**
     * Revokes every session except the one making the request.
     *
     * The control an operator reaches for when they see a session they do not
     * recognise, so it must not sign them out of the device they are on.
     */
    public function signOutOtherSessions(Staff $staff): int
    {
        $current = $staff->currentAccessToken();

        $query = $staff->tokens();

        if ($current instanceof PersonalAccessToken) {
            $query->where('id', '!=', $current->getKey());
        }

        return $query->delete();
    }

    public function signOutAllSessions(Staff $staff): int
    {
        return $staff->tokens()->delete();
    }

    /**
     * Issues an API token carrying the account's permissions as abilities, and
     * records where it was issued from.
     */
    private function issueToken(Staff $staff, RequestContext $context): string
    {
        $lifetimeMinutes = (int) config('naipay.security.token_lifetime_minutes', 480);

        $newToken = $staff->createToken(
            self::TOKEN_NAME,
            $staff->permissionNames()->all(),
            now()->addMinutes($lifetimeMinutes),
        );

        $newToken->accessToken->forceFill([
            'device_name' => $context->deviceName,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'last_activity_at' => now(),
        ])->save();

        $staff->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $context->ipAddress,
            // A pending account activates on its own first successful sign-in
            // — there is no separate "activate" step. What proves it reached
            // the intended recipient is the temporary password (and the
            // forced password change / 2FA enrolment that follows), not an
            // administrator's say-so.
            'status' => $staff->status === StaffStatus::PendingActivation ? StaffStatus::Active : $staff->status,
        ])->save();

        $this->recordAttempt($staff, $staff->email, true, null, $context);
        $this->notifySuperAdministratorsOfSignIn($staff, $context);

        return $newToken->plainTextToken;
    }

    /**
     * Makes every other active Super Administrator passively aware that a
     * sign-in happened — a lightweight security signal, not the audit trail
     * itself (LoginAttempt already covers that). The signer is excluded: a
     * Super Administrator does not need telling about their own sign-in.
     */
    private function notifySuperAdministratorsOfSignIn(Staff $staff, RequestContext $context): void
    {
        $recipients = Staff::query()
            ->whereKeyNot($staff->getKey())
            ->where('status', StaffStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-administrator'))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new StaffSignedInNotification($staff, now(), $context->ipAddress, $context->deviceName),
        );
    }

    /**
     * Stores a pending two-factor challenge.
     *
     * Only a hash of the token is used as the cache key, so a dump of the
     * cache does not yield a usable challenge.
     */
    private function issueChallenge(Staff $staff, RequestContext $context): string
    {
        $challengeToken = Str::random(64);

        Cache::put(
            self::CHALLENGE_CACHE_PREFIX.hash('sha256', $challengeToken),
            [
                'staff_id' => $staff->getKey(),
                'ip_address' => $context->ipAddress,
                'issued_at' => now()->toIso8601String(),
            ],
            self::CHALLENGE_TTL_SECONDS,
        );

        return $challengeToken;
    }

    /**
     * Counts a failure and locks the account once the threshold is reached.
     */
    private function registerFailure(Staff $staff): void
    {
        $maxAttempts = (int) config('naipay.security.max_login_attempts', 5);
        $lockoutMinutes = (int) config('naipay.security.lockout_minutes', 30);

        $attempts = $staff->failed_login_attempts + 1;

        $staff->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maxAttempts ? now()->addMinutes($lockoutMinutes) : null,
        ])->save();
    }

    private function clearFailures(Staff $staff): void
    {
        if ($staff->failed_login_attempts === 0 && $staff->locked_until === null) {
            return;
        }

        $staff->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    private function recordAttempt(
        ?Staff $staff,
        string $identifier,
        bool $successful,
        ?string $failureReason,
        RequestContext $context,
    ): void {
        LoginAttempt::create([
            'staff_id' => $staff?->getKey(),
            // Bounded: the identifier comes straight from the request body.
            'identifier' => mb_substr($identifier, 0, 190),
            'successful' => $successful,
            'failure_reason' => $failureReason,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'device_name' => $context->deviceName,
            'correlation_id' => Correlation::id(),
            'attempted_at' => now(),
        ]);
    }

    /**
     * A real bcrypt hash of a fixed value, used purely to burn the same time a
     * genuine comparison would.
     */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('naipay-timing-equalisation-'.Str::random(8));
    }
}
