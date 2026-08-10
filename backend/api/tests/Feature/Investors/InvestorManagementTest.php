<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Role;
use App\Domains\Investors\Enums\InvestorStatus;
use App\Domains\Investors\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InvestorManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation ------------------------------------------------------------

    #[Test]
    public function an_investor_manager_can_create_an_investor(): void
    {
        $this->actingAsRole(Role::InvestorManager);

        $response = $this->postJson('/api/v1/admin/investors', [
            'name' => 'Adaeze Okafor',
            'email' => 'adaeze.okafor@example.com',
            'phone' => '08012345678',
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^NPI-\d{5}$/', $response->json('data.investor.investor_number'));
        $this->assertSame('active', $response->json('data.investor.status'));
    }

    #[Test]
    public function a_created_investor_receives_a_generated_temporary_password(): void
    {
        $this->actingAsRole(Role::InvestorManager);

        $response = $this->postJson('/api/v1/admin/investors', [
            'name' => 'Chinedu Bello',
            'email' => 'chinedu.bello@example.com',
        ])->assertCreated();

        $temporary = $response->json('data.temporary_password');
        $investor = Investor::query()->where('email', 'chinedu.bello@example.com')->firstOrFail();

        $this->assertIsString($temporary);
        $this->assertTrue(Hash::check($temporary, $investor->password));
    }

    #[Test]
    public function email_addresses_must_be_unique(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        Investor::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/admin/investors', [
            'name' => 'Another Investor',
            'email' => 'taken@example.com',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function a_role_without_the_create_permission_cannot_create_an_investor(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->postJson('/api/v1/admin/investors', [
            'name' => 'Unauthorised Creation',
            'email' => 'nope@example.com',
        ])->assertForbidden();
    }

    #[Test]
    public function the_super_administrator_can_also_create_an_investor(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson('/api/v1/admin/investors', [
            'name' => 'Executive Sponsor',
            'email' => 'executive.sponsor@example.com',
        ])->assertCreated();
    }

    // --- Update ----------------------------------------------------------------

    #[Test]
    public function an_investor_managers_can_update_an_investors_profile(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        $investor = Investor::factory()->create(['phone' => null]);

        $this->patchJson("/api/v1/admin/investors/{$investor->id}", [
            'phone' => '08099998888',
        ])->assertOk()->assertJsonPath('data.phone', '08099998888');

        $this->assertSame('08099998888', $investor->fresh()->phone);
    }

    #[Test]
    public function a_role_without_the_update_permission_cannot_update_an_investor(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $investor = Investor::factory()->create();

        $this->patchJson("/api/v1/admin/investors/{$investor->id}", [
            'phone' => '08099998888',
        ])->assertForbidden();
    }

    // --- Suspend and reinstate ---------------------------------------------

    #[Test]
    public function suspending_an_investor_cuts_every_session_immediately(): void
    {
        $this->actingAsRole(Role::InvestorManager);

        $investor = Investor::factory()->create();
        $investor->createToken('test', ['dashboard:view']);

        $this->assertSame(1, $investor->tokens()->count());

        $this->postJson("/api/v1/admin/investors/{$investor->id}/suspend", [
            'reason' => 'Pending an updated compliance document.',
        ])->assertOk();

        $refreshed = $investor->fresh();

        $this->assertSame(InvestorStatus::Suspended, $refreshed->status);
        $this->assertSame('Pending an updated compliance document.', $refreshed->suspension_reason);
        $this->assertSame(0, $investor->tokens()->count());
    }

    #[Test]
    public function a_suspended_investor_can_be_reinstated(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        $investor = Investor::factory()->suspended()->create();

        $this->postJson("/api/v1/admin/investors/{$investor->id}/reinstate", [
            'reason' => 'Updated compliance document received.',
        ])->assertOk();

        $refreshed = $investor->fresh();

        $this->assertSame(InvestorStatus::Active, $refreshed->status);
        $this->assertNull($refreshed->suspension_reason);
    }

    #[Test]
    public function a_role_without_the_suspend_permission_cannot_change_an_investors_status(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $investor = Investor::factory()->create();

        $this->postJson("/api/v1/admin/investors/{$investor->id}/suspend", [
            'reason' => 'Attempting an unauthorised suspension.',
        ])->assertForbidden();
    }

    #[Test]
    public function a_status_change_requires_a_meaningful_reason(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        $investor = Investor::factory()->create();

        $this->postJson("/api/v1/admin/investors/{$investor->id}/suspend", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function a_suspension_is_audited_with_the_reason(): void
    {
        $actor = $this->actingAsRole(Role::InvestorManager);
        $investor = Investor::factory()->create();

        $this->postJson("/api/v1/admin/investors/{$investor->id}/suspend", [
            'reason' => 'Investment exited; access no longer required.',
        ])->assertOk();

        $entry = AuditLog::query()->where('action', 'investor.suspended')->firstOrFail();

        $this->assertSame('Investment exited; access no longer required.', $entry->reason);
        $this->assertSame($actor->id, $entry->staff_id);
    }

    // --- Listing -------------------------------------------------------------

    #[Test]
    public function investors_can_be_listed_and_searched(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        Investor::factory()->create(['name' => 'Findable Investor']);
        Investor::factory()->create(['name' => 'Someone Else']);

        $names = collect($this->getJson('/api/v1/admin/investors?search=Findable')->assertOk()->json('data'))
            ->pluck('name');

        $this->assertTrue($names->contains('Findable Investor'));
        $this->assertFalse($names->contains('Someone Else'));
    }

    #[Test]
    public function a_role_without_the_view_permission_cannot_list_investors(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->getJson('/api/v1/admin/investors')->assertForbidden();
    }

    #[Test]
    public function the_investor_list_never_exposes_credentials(): void
    {
        $this->actingAsRole(Role::InvestorManager);
        Investor::factory()->create();

        $body = (string) $this->getJson('/api/v1/admin/investors')->assertOk()->getContent();

        $this->assertStringNotContainsString('"password"', $body);
    }
}
