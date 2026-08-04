<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        RateLimiter::clear('authentication');
    }

    #[Test]
    public function a_staff_member_can_enrol_in_two_factor(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $response = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_code_svg']]);

        $this->assertStringContainsString('<svg', $response->json('data.qr_code_svg'));
        $this->assertStringContainsString('Naipay', $response->json('data.otpauth_url'));

        // Enrolling issues a secret but must NOT activate two-factor — an
        // operator who cannot produce a code from it would be locked out.
        $this->assertFalse($staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function enrolment_is_completed_by_confirming_a_code(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $response = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ])->assertOk();

        $this->assertTrue($staff->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    #[Test]
    public function confirmation_is_refused_with_a_wrong_code(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol');

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function sign_in_with_two_factor_enabled_returns_a_challenge_rather_than_a_token(): void
    {
        $staff = Staff::factory()->withTwoFactor()->create();

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->assertOk();

        $this->assertTrue($response->json('data.requires_two_factor'));
        $this->assertNotNull($response->json('data.challenge_token'));

        // Critically, no usable token is issued by the first factor alone.
        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function a_valid_code_completes_the_challenge_and_issues_a_token(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $staff = Staff::factory()->withTwoFactor($secret)->create();

        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $response = $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->currentCode($secret),
        ])->assertOk();

        $this->assertNotNull($response->json('data.token'));

        $this->tokenRequest($response->json('data.token'), 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function an_incorrect_code_is_refused(): void
    {
        $staff = Staff::factory()->withTwoFactor()->create();

        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => '111111',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_challenge_token_is_single_use(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $staff = Staff::factory()->withTwoFactor($secret)->create();

        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->currentCode($secret),
        ])->assertOk();

        // Replaying it must not yield a second token.
        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->currentCode($secret),
        ])->assertStatus(401);
    }

    #[Test]
    public function a_fabricated_challenge_token_is_refused(): void
    {
        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => str_repeat('a', 64),
            'code' => '123456',
        ])->assertStatus(401);
    }

    #[Test]
    public function a_challenge_expires(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $staff = Staff::factory()->withTwoFactor($secret)->create();

        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $this->currentCode($secret),
        ])->assertStatus(401);
    }

    #[Test]
    public function a_recovery_code_can_complete_a_challenge_and_is_then_consumed(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $recoveryCodes = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ])->json('data.recovery_codes');

        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $recoveryCodes[0],
        ])->assertOk();

        $this->assertCount(7, $staff->fresh()->two_factor_recovery_codes);

        // The same code must not work twice.
        $secondChallenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $secondChallenge,
            'code' => $recoveryCodes[0],
        ])->assertStatus(401);
    }

    #[Test]
    public function recovery_codes_are_stored_hashed(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $recoveryCodes = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ])->json('data.recovery_codes');

        $stored = $staff->fresh()->two_factor_recovery_codes;

        // A recovery code is a credential; a database read must not yield a
        // working one.
        $this->assertNotContains($recoveryCodes[0], $stored);
        $this->assertStringStartsWith('$2y$', $stored[0]);
    }

    #[Test]
    public function the_two_factor_secret_is_encrypted_at_rest(): void
    {
        $staff = Staff::factory()->withTwoFactor('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')->create();

        $raw = DB::table('staff')
            ->where('id', $staff->id)
            ->value('two_factor_secret');

        $this->assertNotSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $raw);
        $this->assertSame('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $staff->fresh()->two_factor_secret);
    }

    #[Test]
    public function two_factor_is_never_exposed_in_the_staff_payload(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $staff = Staff::factory()->withTwoFactor($secret)->create();
        $staff->assignRole(Role::CustomerSupport->value);

        $token = $this->signInWithTwoFactor($staff, StaffFactory::PASSWORD, $secret);

        $body = (string) $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->getContent();

        $this->assertStringNotContainsString('two_factor_secret', $body);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $body);
        $this->assertStringNotContainsString($secret, $body);
    }

    #[Test]
    public function a_privileged_role_cannot_disable_two_factor(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $staff = Staff::factory()->withTwoFactor($secret)->create();
        // Finance Manager holds ledger posting and disbursement permissions, so
        // a single factor must never be sufficient for this account.
        $staff->assignRole(Role::FinanceManager->value);

        $this->assertTrue($staff->fresh()->requiresTwoFactor());

        $token = $this->signInWithTwoFactor($staff, StaffFactory::PASSWORD, $secret);

        $this->tokenRequest($token, 'DELETE', '/api/v1/admin/auth/two-factor')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function a_role_without_privileged_permissions_may_disable_two_factor(): void
    {
        $staff = Staff::factory()->create();
        $staff->assignRole(Role::CustomerSupport->value);

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ])->assertOk();

        $this->tokenRequest($token, 'DELETE', '/api/v1/admin/auth/two-factor')->assertOk();

        $this->assertFalse($staff->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function a_staff_member_whose_role_mandates_two_factor_is_blocked_until_they_enrol(): void
    {
        $staff = Staff::factory()->create();
        $staff->assignRole(Role::FinanceManager->value);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->assertOk();

        $this->assertSame('enrol_two_factor', $response->json('data.required_action'));

        $token = $response->json('data.token');

        $blocked = $this->tokenRequest($token, 'GET', '/api/v1/admin/account/sessions')
            ->assertStatus(403);

        $this->assertSame('enrol_two_factor', $blocked->headers->get('X-Naipay-Required-Action'));

        // The enrolment endpoint itself stays reachable.
        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')->assertOk();
    }

    #[Test]
    public function recovery_codes_can_be_regenerated(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $original = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ])->json('data.recovery_codes');

        $regenerated = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/recovery-codes')
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $regenerated);
        $this->assertNotEquals($original, $regenerated);

        // An old code must no longer work.
        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->json('data.challenge_token');

        $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => $original[0],
        ])->assertStatus(401);
    }

    #[Test]
    public function two_factor_status_reports_remaining_recovery_codes(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $secret = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/enrol')
            ->json('data.secret');

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/two-factor/confirm', [
            'code' => $this->currentCode($secret),
        ]);

        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/two-factor')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_remaining', 8);
    }

    /**
     * Produces the code an authenticator app would be showing right now.
     */
    private function currentCode(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
