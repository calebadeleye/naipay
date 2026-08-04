<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Identity\Database\Seeders\RolePermissionSeeder;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seeds roles and permissions, and clears the permission cache.
     *
     * The cache is process-wide and survives the database rollback between
     * tests, so without the flush a test can see the permission map left by
     * the previous one.
     */
    protected function seedRolesAndPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Creates a staff member holding the given role and signs them in.
     */
    protected function actingAsRole(Role $role, array $attributes = []): Staff
    {
        $this->seedRolesAndPermissions();

        $staff = Staff::factory()->create($attributes);
        $staff->assignRole($role->value);

        // Re-read before acting: a factory instance holds only the columns it
        // inserted, so attributes that exist purely as database defaults are
        // absent, and strict mode rightly refuses to read them. A real request
        // always resolves a fully hydrated model.
        $staff = $staff->fresh();

        // Roles holding privileged permissions cannot use the console until
        // two-factor is enrolled — `security.steps` refuses every other
        // endpoint. Satisfying it here mirrors the only state such an account
        // can actually be in; the enforcement itself is covered by its own
        // tests rather than incidentally by every authorisation test.
        if ($staff->requiresTwoFactor() && ! $staff->hasTwoFactorEnabled()) {
            $staff->forceFill([
                'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
                'two_factor_recovery_codes' => [],
                'two_factor_confirmed_at' => now(),
            ])->save();

            $staff = $staff->fresh();
        }

        // Abilities mirror what AuthenticationService grants on a real
        // sign-in, so ability-gated routes behave the same under test.
        Sanctum::actingAs($staff, $staff->permissionNames()->all(), 'staff');

        return $staff;
    }

    /**
     * Creates a staff member holding exactly the given permissions, with no
     * role — for asserting that a single permission is what gates an endpoint.
     *
     * @param  array<int, Permission>  $permissions
     */
    protected function actingAsStaffWith(array $permissions, array $attributes = []): Staff
    {
        $this->seedRolesAndPermissions();

        $staff = Staff::factory()->create($attributes);

        $staff->givePermissionTo(array_map(
            static fn (Permission $permission): string => $permission->value,
            $permissions,
        ));

        $staff = $staff->fresh();

        Sanctum::actingAs($staff, $staff->permissionNames()->all(), 'staff');

        return $staff;
    }

    /**
     * Signs in over HTTP and returns the bearer token, so a test exercises the
     * real authentication path rather than Sanctum's test double.
     */
    protected function signIn(Staff $staff, string $password): string
    {
        $response = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => $password,
        ])->assertOk();

        return $response->json('data.token');
    }

    /**
     * Signs in an account that has two-factor enabled, completing the
     * challenge with a code derived from its secret.
     */
    protected function signInWithTwoFactor(Staff $staff, string $password, string $secret): string
    {
        $challenge = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $staff->email,
            'password' => $password,
        ])->assertOk()->json('data.challenge_token');

        return $this->postJson('/api/v1/admin/auth/two-factor/challenge', [
            'challenge_token' => $challenge,
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
        ])->assertOk()->json('data.token');
    }

    /**
     * Issues a request carrying a bearer token.
     *
     * The guard is reset first. A test process keeps one container across
     * requests, and the auth guard caches the user it resolved, so without
     * this a token revoked mid-test would still appear to authenticate — the
     * assertion would pass against a stale in-memory user rather than against
     * the database. Production has no such carry-over: every request gets a
     * fresh container.
     *
     * @param  array<string, mixed>  $data
     */
    protected function tokenRequest(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $data);
    }
}
