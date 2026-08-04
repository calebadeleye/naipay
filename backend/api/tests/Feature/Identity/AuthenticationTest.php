<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Models\LoginAttempt;
use App\Domains\Identity\Models\Staff;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();

        // The route throttle is state that survives between tests; without
        // clearing it, later tests inherit earlier tests' attempt counts.
        RateLimiter::clear('authentication');
    }

    #[Test]
    public function a_staff_member_can_sign_in_with_their_email(): void
    {
        $staff = Staff::factory()->create();

        $this->login($staff->email, StaffFactory::PASSWORD)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.staff.email', $staff->email)
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'expires_in', 'staff', 'required_action'],
            ]);
    }

    #[Test]
    public function a_staff_member_can_sign_in_with_their_username(): void
    {
        $staff = Staff::factory()->create(['username' => 'a.okafor']);

        $this->login('a.okafor', StaffFactory::PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.staff.username', 'a.okafor');
    }

    #[Test]
    public function the_issued_token_authenticates_subsequent_requests(): void
    {
        $staff = Staff::factory()->create();

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $staff->email);
    }

    #[Test]
    public function an_incorrect_password_is_refused(): void
    {
        $staff = Staff::factory()->create();

        $this->login($staff->email, 'not-the-password')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function an_unknown_identifier_is_refused_identically_to_a_wrong_password(): void
    {
        $staff = Staff::factory()->create();

        $unknown = $this->login('nobody@naitalk.com', 'whatever')->assertStatus(401);
        $wrongPassword = $this->login($staff->email, 'not-the-password')->assertStatus(401);

        // Identical messages: any difference lets an attacker enumerate which
        // staff accounts exist.
        $this->assertSame(
            $wrongPassword->json('message'),
            $unknown->json('message'),
        );
    }

    #[Test]
    public function every_attempt_is_recorded_whether_it_succeeds_or_fails(): void
    {
        $staff = Staff::factory()->create();

        $this->login($staff->email, 'wrong');
        $this->login($staff->email, StaffFactory::PASSWORD);

        $attempts = LoginAttempt::query()->where('staff_id', $staff->id)->get();

        $this->assertCount(2, $attempts);
        $this->assertTrue($attempts->contains(fn (LoginAttempt $a): bool => $a->successful === false));
        $this->assertTrue($attempts->contains(fn (LoginAttempt $a): bool => $a->successful === true));

        // Provenance is captured for the login-history screen.
        $this->assertNotNull($attempts->first()->ip_address);
        $this->assertNotNull($attempts->first()->correlation_id);
    }

    #[Test]
    public function an_attempt_against_an_unknown_identifier_is_still_recorded(): void
    {
        $this->login('intruder@example.com', 'guess');

        $attempt = LoginAttempt::query()->where('identifier', 'intruder@example.com')->first();

        $this->assertNotNull($attempt);
        $this->assertNull($attempt->staff_id);
        $this->assertFalse($attempt->successful);
    }

    #[Test]
    public function a_password_is_never_written_to_the_login_attempt_record(): void
    {
        $staff = Staff::factory()->create();

        $this->login($staff->email, 'super-secret-password');

        $attempts = LoginAttempt::query()->get()->toJson();

        $this->assertStringNotContainsString('super-secret-password', $attempts);
    }

    #[Test]
    public function an_account_locks_after_the_configured_number_of_failures(): void
    {
        config(['naipay.security.max_login_attempts' => 3]);

        $staff = Staff::factory()->create();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->login($staff->email, 'wrong')->assertStatus(401);
        }

        $this->assertNull($staff->fresh()->locked_until);

        // The third failure trips the lock.
        $this->login($staff->email, 'wrong')->assertStatus(401);

        $this->assertTrue($staff->fresh()->isLocked());

        // And the correct password no longer works while locked.
        $this->login($staff->email, StaffFactory::PASSWORD)
            ->assertStatus(423)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function the_failure_counter_resets_after_a_successful_sign_in(): void
    {
        config(['naipay.security.max_login_attempts' => 5]);

        $staff = Staff::factory()->create();

        $this->login($staff->email, 'wrong');
        $this->login($staff->email, 'wrong');

        $this->assertSame(2, $staff->fresh()->failed_login_attempts);

        $this->login($staff->email, StaffFactory::PASSWORD)->assertOk();

        $this->assertSame(0, $staff->fresh()->failed_login_attempts);
    }

    #[Test]
    public function a_lock_expires_on_its_own(): void
    {
        $staff = Staff::factory()->locked(minutes: 30)->create();

        $this->login($staff->email, StaffFactory::PASSWORD)->assertStatus(423);

        $this->travel(31)->minutes();

        $this->login($staff->email, StaffFactory::PASSWORD)->assertOk();
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in(): void
    {
        $staff = Staff::factory()->suspended()->create();

        $this->login($staff->email, StaffFactory::PASSWORD)
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_disabled_account_cannot_sign_in(): void
    {
        $staff = Staff::factory()->disabled()->create();

        $this->login($staff->email, StaffFactory::PASSWORD)->assertStatus(403);
    }

    #[Test]
    public function an_account_status_is_only_revealed_once_the_password_is_proven(): void
    {
        $staff = Staff::factory()->suspended()->create();

        // Wrong password against a suspended account must look like any other
        // wrong password — otherwise the refusal confirms the account exists.
        $this->login($staff->email, 'wrong')->assertStatus(401);
    }

    #[Test]
    public function a_soft_deleted_staff_member_cannot_sign_in(): void
    {
        $staff = Staff::factory()->create();
        $staff->delete();

        $this->login($staff->email, StaffFactory::PASSWORD)->assertStatus(401);
    }

    #[Test]
    public function an_account_created_by_an_administrator_must_change_its_password(): void
    {
        $staff = Staff::factory()->mustChangePassword()->create();

        $this->login($staff->email, StaffFactory::PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.required_action', 'change_password');
    }

    #[Test]
    public function a_staff_member_owing_a_password_change_is_blocked_from_normal_endpoints(): void
    {
        $staff = Staff::factory()->mustChangePassword()->create();

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $response = $this->tokenRequest($token, 'GET', '/api/v1/admin/account/sessions')
            ->assertStatus(403);

        $this->assertSame('change_password', $response->headers->get('X-Naipay-Required-Action'));

        // But can still reach the endpoints needed to resolve it.
        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function signing_out_revokes_the_token_used(): void
    {
        $staff = Staff::factory()->create();

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/logout')->assertOk();

        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
    }

    #[Test]
    public function protected_endpoints_reject_an_absent_token(): void
    {
        $this->getJson('/api/v1/admin/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function protected_endpoints_reject_a_fabricated_token(): void
    {
        $this->tokenRequest('1|totally-made-up-token', 'GET', '/api/v1/admin/auth/me')
            ->assertStatus(401);
    }

    #[Test]
    public function sign_in_attempts_are_rate_limited(): void
    {
        $staff = Staff::factory()->create();

        // The per-identifier limiter allows five attempts a minute; the sixth
        // is refused by the throttle before it reaches the controller.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login($staff->email, 'wrong');
        }

        $this->login($staff->email, 'wrong')->assertStatus(429);
    }

    #[Test]
    public function the_last_login_details_are_recorded(): void
    {
        $staff = Staff::factory()->create();

        $this->assertNull($staff->last_login_at);

        $this->login($staff->email, StaffFactory::PASSWORD)->assertOk();

        $refreshed = $staff->fresh();

        $this->assertNotNull($refreshed->last_login_at);
        $this->assertNotNull($refreshed->last_login_ip);
    }

    #[Test]
    public function sign_in_requires_both_fields(): void
    {
        $this->postJson('/api/v1/admin/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['identifier', 'password']]);
    }

    private function login(string $identifier, string $password): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $identifier,
            'password' => $password,
        ]);
    }
}
