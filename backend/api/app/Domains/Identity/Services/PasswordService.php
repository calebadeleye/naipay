<?php

declare(strict_types=1);

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Notifications\PasswordChangedNotification;
use App\Domains\Identity\Notifications\PasswordResetNotification;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password changes and resets.
 *
 * Both paths revoke every existing session. If a password changed because it
 * may have been compromised, leaving the old tokens alive would defeat the
 * point of changing it.
 */
final class PasswordService
{
    /**
     * Reset links are short-lived: the email is the weakest link in the chain,
     * and an old message in an inbox should not stay usable.
     */
    private const RESET_TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    /**
     * Changes a signed-in operator's own password.
     *
     * The current password is required even though the session is already
     * authenticated — it is what stops an unattended workstation being used to
     * take over the account outright.
     */
    public function change(Staff $staff, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $staff->password)) {
            throw new DomainException(
                'Your current password is incorrect.',
                ['current_password' => ['The current password is incorrect.']],
            );
        }

        if (Hash::check($newPassword, $staff->password)) {
            throw new DomainException(
                'Choose a password you have not used before.',
                ['password' => ['The new password must be different from your current password.']],
            );
        }

        $this->setPassword($staff, $newPassword);

        // Everything except the session making the change: the operator stays
        // signed in where they are, and anything else is cut off.
        $this->authentication->signOutOtherSessions($staff);

        $staff->notify(new PasswordChangedNotification);
    }

    /**
     * Issues a reset token and emails a link.
     *
     * Returns silently when the address matches no account. Reporting "no such
     * account" here would turn the endpoint into an enumeration oracle, and it
     * is reachable without authentication.
     */
    public function sendResetLink(string $email): void
    {
        $email = mb_strtolower(trim($email));

        $staff = Staff::query()->where('email', $email)->first();

        if ($staff === null || ! $staff->status->canAuthenticate()) {
            return;
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                // Only the hash is stored; a read of this table yields no
                // working link.
                'token' => Hash::make($token),
                'created_at' => now(),
            ],
        );

        $staff->notify(new PasswordResetNotification($token, self::RESET_TOKEN_TTL_MINUTES));
    }

    /**
     * Completes a reset.
     */
    public function reset(string $email, string $token, string $newPassword): void
    {
        $email = mb_strtolower(trim($email));

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if ($record === null || ! Hash::check($token, $record->token)) {
            throw new DomainException(
                'This password reset link is not valid. Request a new one.',
                ['token' => ['The reset link is invalid.']],
            );
        }

        if (now()->diffInMinutes($record->created_at, absolute: true) > self::RESET_TOKEN_TTL_MINUTES) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            throw new DomainException(
                'This password reset link has expired. Request a new one.',
                ['token' => ['The reset link has expired.']],
            );
        }

        $staff = Staff::query()->where('email', $email)->firstOrFail();

        $this->setPassword($staff, $newPassword);

        // Single-use.
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // A reset is the recovery path for a lost or compromised password, so
        // every existing session is cut, and any lockout is lifted.
        $this->authentication->signOutAllSessions($staff);

        $staff->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $staff->notify(new PasswordChangedNotification);
    }

    /**
     * Sets a password chosen by an administrator, forcing a change at next
     * sign-in so the operator ends up with a secret nobody else has seen.
     */
    public function setTemporaryPassword(Staff $staff, string $password): void
    {
        $staff->forceFill([
            'password' => $password,
            'password_changed_at' => now(),
            'must_change_password' => true,
        ])->save();

        $this->authentication->signOutAllSessions($staff);
    }

    private function setPassword(Staff $staff, string $password): void
    {
        $staff->forceFill([
            // The model's `hashed` cast handles hashing.
            'password' => $password,
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();
    }
}
