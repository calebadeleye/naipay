<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Services;

use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Notifications\MerchantActivationNotification;
use App\Domains\Merchants\Notifications\MerchantPasswordChangedNotification;
use App\Domains\Merchants\Notifications\MerchantPasswordResetNotification;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Merchant portal account activation and password reset.
 *
 * Merchants are created by staff during onboarding with no password at all —
 * issuing thousands of merchant passwords by hand doesn't scale, so a
 * merchant sets their own the first time, via an emailed link, the same way
 * PasswordService::sendResetLink()/reset() work for staff. Activation and
 * reset share one code path (`complete()`) and one token table
 * (`merchant_password_reset_tokens`) — the only difference is which
 * notification is sent and whether `activated_at` was already set.
 */
final class MerchantPasswordService
{
    /**
     * Reset/activation links are short-lived: the email is the weakest link
     * in the chain, and an old message in an inbox should not stay usable.
     */
    private const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly MerchantAuthenticationService $authentication,
    ) {}

    /**
     * Issues an activation token and emails a link.
     *
     * Returns silently when the address matches no account, is already
     * activated, or belongs to a merchant not yet approved — reporting any of
     * those here would turn the endpoint into an enumeration oracle, and it
     * is reachable without authentication.
     */
    public function requestActivation(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $merchant = Merchant::query()->where('email', $email)->first();

        if ($merchant === null || $merchant->password !== null || ! $merchant->isApproved()) {
            return;
        }

        $token = $this->issueToken($email);

        $merchant->notify(new MerchantActivationNotification($token, self::TOKEN_TTL_MINUTES));
    }

    /**
     * Issues a reset token and emails a link, for a merchant who already has
     * a password. Silent on a non-match, for the same reason as above.
     */
    public function sendResetLink(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $merchant = Merchant::query()->where('email', $email)->first();

        if ($merchant === null || $merchant->password === null) {
            return;
        }

        $token = $this->issueToken($email);

        $merchant->notify(new MerchantPasswordResetNotification($token, self::TOKEN_TTL_MINUTES));
    }

    /**
     * Completes either an activation or a reset — whichever this token was
     * issued for. The record itself doesn't distinguish them; the merchant's
     * current `password`/`activated_at` state does.
     */
    public function complete(string $email, string $token, string $newPassword): void
    {
        $email = mb_strtolower(trim($email));

        $record = DB::table('merchant_password_reset_tokens')->where('email', $email)->first();

        if ($record === null || ! Hash::check($token, $record->token)) {
            throw new DomainException(
                'This link is not valid. Request a new one.',
                ['token' => ['The link is invalid.']],
            );
        }

        if (now()->diffInMinutes($record->created_at, absolute: true) > self::TOKEN_TTL_MINUTES) {
            DB::table('merchant_password_reset_tokens')->where('email', $email)->delete();

            throw new DomainException(
                'This link has expired. Request a new one.',
                ['token' => ['The link has expired.']],
            );
        }

        $merchant = Merchant::query()->where('email', $email)->firstOrFail();

        $merchant->forceFill([
            // The model's `hashed` cast handles hashing.
            'password' => $newPassword,
            'activated_at' => $merchant->activated_at ?? now(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        // Single-use.
        DB::table('merchant_password_reset_tokens')->where('email', $email)->delete();

        // Recovery/activation is the point a lost or never-set password stops
        // being a risk, so every existing session is cut.
        $this->authentication->signOutAllSessions($merchant);

        $merchant->notify(new MerchantPasswordChangedNotification);
    }

    private function issueToken(string $email): string
    {
        $token = Str::random(64);

        DB::table('merchant_password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                // Only the hash is stored; a read of this table yields no
                // working link.
                'token' => Hash::make($token),
                'created_at' => now(),
            ],
        );

        return $token;
    }
}
