<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Models\Staff;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        RateLimiter::clear('authentication');
    }

    #[Test]
    public function a_staff_member_can_see_their_active_sessions(): void
    {
        $staff = Staff::factory()->create();

        $this->signIn($staff, StaffFactory::PASSWORD);
        $current = $this->signIn($staff, StaffFactory::PASSWORD);

        $response = $this->tokenRequest($current, 'GET', '/api/v1/admin/account/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $sessions = $response->json('data');

        // Exactly one is flagged current, so the console can stop the operator
        // revoking the device in front of them by mistake.
        $this->assertSame(1, count(array_filter($sessions, fn (array $s): bool => $s['is_current'] === true)));

        $this->assertArrayHasKey('device_name', $sessions[0]);
        $this->assertArrayHasKey('ip_address', $sessions[0]);
        $this->assertArrayHasKey('last_activity_at', $sessions[0]);
    }

    #[Test]
    public function the_session_list_never_exposes_the_token(): void
    {
        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $body = (string) $this->tokenRequest($token, 'GET', '/api/v1/admin/account/sessions')->getContent();

        $this->assertStringNotContainsString('token', str_replace(['token_type'], '', $body));
    }

    #[Test]
    public function device_provenance_is_recorded_against_a_session(): void
    {
        $staff = Staff::factory()->create();

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        ])->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => StaffFactory::PASSWORD,
        ])->assertOk();

        $this->assertSame('Chrome on Windows', $staff->tokens()->first()->device_name);
    }

    #[Test]
    public function a_staff_member_can_revoke_a_single_session(): void
    {
        $staff = Staff::factory()->create();

        $other = $this->signIn($staff, StaffFactory::PASSWORD);
        $current = $this->signIn($staff, StaffFactory::PASSWORD);

        $sessions = $this->tokenRequest($current, 'GET', '/api/v1/admin/account/sessions')->json('data');

        $otherId = collect($sessions)->firstWhere('is_current', false)['id'];

        $this->tokenRequest($current, 'DELETE', "/api/v1/admin/account/sessions/{$otherId}")->assertOk();

        $this->tokenRequest($other, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->tokenRequest($current, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function a_staff_member_cannot_revoke_another_persons_session(): void
    {
        $staff = Staff::factory()->create();
        $colleague = Staff::factory()->create();

        $colleagueToken = $this->signIn($colleague, StaffFactory::PASSWORD);
        $colleagueSessionId = $colleague->tokens()->first()->id;

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        // The lookup is scoped to the caller's own tokens, so another staff
        // member's id simply is not found.
        $this->tokenRequest($token, 'DELETE', "/api/v1/admin/account/sessions/{$colleagueSessionId}")
            ->assertNotFound();

        $this->tokenRequest($colleagueToken, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function a_staff_member_can_sign_out_every_other_session(): void
    {
        $staff = Staff::factory()->create();

        $first = $this->signIn($staff, StaffFactory::PASSWORD);
        $second = $this->signIn($staff, StaffFactory::PASSWORD);
        $current = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($current, 'DELETE', '/api/v1/admin/account/sessions/others')
            ->assertOk()
            ->assertJsonPath('data.sessions_revoked', 2);

        $this->tokenRequest($first, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->tokenRequest($second, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->tokenRequest($current, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    #[Test]
    public function a_session_expires_after_the_configured_idle_period(): void
    {
        config(['naipay.security.session_idle_minutes' => 30]);

        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertOk();

        $this->travel(31)->minutes();

        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);

        // The token is deleted rather than merely rejected, so a stolen bearer
        // cannot be replayed later.
        $this->assertSame(0, $staff->tokens()->count());
    }

    #[Test]
    public function activity_keeps_a_session_alive(): void
    {
        config(['naipay.security.session_idle_minutes' => 30]);

        $staff = Staff::factory()->create();
        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        // Working steadily should never expire the session, even well beyond
        // the idle window in total elapsed time.
        for ($i = 0; $i < 4; $i++) {
            $this->travel(20)->minutes();
            $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertOk();
        }
    }

    #[Test]
    public function a_staff_member_can_review_their_login_history(): void
    {
        $staff = Staff::factory()->create();

        $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => 'wrong',
        ])->assertStatus(401);

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $response = $this->tokenRequest($token, 'GET', '/api/v1/admin/account/login-history')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'successful', 'failure_reason', 'ip_address', 'device_name', 'attempted_at']],
                'meta' => ['pagination'],
            ]);

        $entries = $response->json('data');

        $this->assertCount(2, $entries);
        // Newest first.
        $this->assertTrue($entries[0]['successful']);
        $this->assertFalse($entries[1]['successful']);
        $this->assertSame('Incorrect password', $entries[1]['failure_label']);
    }

    #[Test]
    public function login_history_shows_only_the_callers_own_attempts(): void
    {
        $staff = Staff::factory()->create();
        $colleague = Staff::factory()->create();

        $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $colleague->email,
            'password' => 'wrong',
        ]);

        $token = $this->signIn($staff, StaffFactory::PASSWORD);

        $entries = $this->tokenRequest($token, 'GET', '/api/v1/admin/account/login-history')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $entries);
    }
}
