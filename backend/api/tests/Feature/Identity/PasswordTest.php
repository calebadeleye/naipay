<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Notifications\PasswordChangedNotification;
use App\Domains\Identity\Notifications\PasswordResetNotification;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Naipay-Brand-New-9!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        RateLimiter::clear('authentication');
        Notification::fake();
    }

    // --- Changing a password -----------------------------------------------

    #[Test]
    public function a_staff_member_can_change_their_own_password(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => StaffFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $staff->fresh()->password));

        // And the new password works for a fresh sign-in.
        $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function changing_a_password_requires_the_current_one(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        // An unattended workstation must not be enough to take over the
        // account outright.
        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => 'not-the-current-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['current_password']]);

        $this->assertTrue(Hash::check(StaffFactory::PASSWORD, $staff->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => StaffFactory::PASSWORD,
            'password' => StaffFactory::PASSWORD,
            'password_confirmation' => StaffFactory::PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_weak_password_is_refused(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        foreach (['short1!A', 'alllowercase123!', 'NOSYMBOLSORLOWER123', 'NoNumbersHere!!'] as $weak) {
            $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
                'current_password' => StaffFactory::PASSWORD,
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertStatus(422)
                ->assertJsonStructure(['errors' => ['password']]);
        }
    }

    #[Test]
    public function changing_a_password_signs_out_other_sessions_but_not_this_one(): void
    {
        $staff = Staff::factory()->create();

        $otherSession = $this->signIn($staff, StaffFactory::PASSWORD);
        $currentSession = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->assertSame(2, $staff->tokens()->count());

        $this->tokenRequest($currentSession, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => StaffFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // The operator stays signed in where they are.
        $this->tokenRequest($currentSession, 'GET', '/api/v1/admin/auth/me')->assertOk();

        // Anything else is cut off — the point of changing a password that may
        // have been compromised.
        $this->tokenRequest($otherSession, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
    }

    #[Test]
    public function changing_a_password_clears_the_forced_change_flag(): void
    {
        $staff = Staff::factory()->mustChangePassword()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => StaffFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertFalse($staff->fresh()->must_change_password);

        // And normal endpoints open up.
        $this->tokenRequest($token, 'GET', '/api/v1/admin/account/sessions')->assertOk();
    }

    #[Test]
    public function a_password_change_notifies_the_account_holder(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/password', [
            'current_password' => StaffFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // Sent even when they made the change themselves — the value is in the
        // case where they did not.
        Notification::assertSentTo($staff, PasswordChangedNotification::class);
    }

    // --- Forgotten password ------------------------------------------------

    #[Test]
    public function a_reset_link_is_emailed_for_a_known_address(): void
    {
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->email])
            ->assertOk();

        Notification::assertSentTo($staff, PasswordResetNotification::class);
    }

    #[Test]
    public function the_forgot_password_endpoint_does_not_reveal_whether_an_account_exists(): void
    {
        $staff = Staff::factory()->create();

        $known = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->email])->assertOk();
        $unknown = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'nobody@naitalk.com'])->assertOk();

        // Identical responses: this endpoint is unauthenticated, and any
        // difference turns it into an account-enumeration oracle.
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame($known->status(), $unknown->status());
    }

    #[Test]
    public function no_reset_link_is_sent_to_a_suspended_account(): void
    {
        $staff = Staff::factory()->suspended()->create();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->email])->assertOk();

        Notification::assertNothingSent();
    }

    #[Test]
    public function the_reset_token_is_stored_hashed(): void
    {
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->email])->assertOk();

        $stored = DB::table('password_reset_tokens')->where('email', $staff->email)->value('token');

        // A read of this table must not yield a working reset link.
        $this->assertStringStartsWith('$2y$', (string) $stored);
    }

    // --- Resetting a password ----------------------------------------------

    #[Test]
    public function a_password_can_be_reset_with_a_valid_token(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->requestResetToken($staff);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $staff->fresh()->password));
    }

    #[Test]
    public function a_reset_token_is_single_use(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->requestResetToken($staff);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => 'Another-Password-1!',
            'password_confirmation' => 'Another-Password-1!',
        ])->assertStatus(422);
    }

    #[Test]
    public function an_invalid_reset_token_is_refused(): void
    {
        $staff = Staff::factory()->create();
        $this->requestResetToken($staff);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => str_repeat('x', 64),
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);

        $this->assertTrue(Hash::check(StaffFactory::PASSWORD, $staff->fresh()->password));
    }

    #[Test]
    public function an_expired_reset_token_is_refused(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->requestResetToken($staff);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);
    }

    #[Test]
    public function resetting_a_password_revokes_every_session(): void
    {
        $staff = Staff::factory()->create();

        $sessionOne = $this->signIn($staff, StaffFactory::PASSWORD);
        $sessionTwo = $this->signIn($staff, StaffFactory::PASSWORD);

        $token = $this->requestResetToken($staff);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        // A reset is the recovery path for a compromised password, so unlike a
        // change, nothing survives it.
        $this->tokenRequest($sessionOne, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->tokenRequest($sessionTwo, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
    }

    #[Test]
    public function resetting_a_password_lifts_an_account_lock(): void
    {
        $staff = Staff::factory()->locked()->create();

        $token = $this->requestResetToken($staff);

        $this->postJson('/api/v1/admin/auth/reset-password', [
            'email' => $staff->email,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $refreshed = $staff->fresh();

        $this->assertFalse($refreshed->isLocked());
        $this->assertSame(0, $refreshed->failed_login_attempts);

        $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    /**
     * Captures the plain-text reset token from the queued notification, which
     * is the only place it exists after the request completes.
     */
    private function requestResetToken(Staff $staff): string
    {
        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $staff->email])->assertOk();

        $captured = null;

        Notification::assertSentTo(
            $staff,
            PasswordResetNotification::class,
            function (PasswordResetNotification $notification) use (&$captured, $staff): bool {
                $mail = $notification->toMail($staff);

                parse_str(
                    (string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY),
                    $query,
                );

                $captured = $query['token'] ?? null;

                return true;
            },
        );

        $this->assertIsString($captured, 'No reset token was found in the notification.');

        return $captured;
    }
}
