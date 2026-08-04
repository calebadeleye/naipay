<?php

declare(strict_types=1);

namespace Tests\Feature\Branches;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_authorised_administrator_can_create_a_branch(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $response = $this->postJson('/api/v1/admin/branches', [
            'name' => 'Ikeja Branch',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'phone' => '08031234567',
            'opened_at' => '2026-01-15',
        ])->assertCreated();

        // The code is allocated from the branch sequence, not supplied.
        $this->assertMatchesRegularExpression('/^NPBR-\d{3}$/', $response->json('data.branch_code'));
        $this->assertSame('Ikeja Branch', $response->json('data.name'));
        $this->assertTrue($response->json('data.accepts_new_business'));
    }

    #[Test]
    public function creating_a_branch_is_recorded_in_the_audit_trail(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson('/api/v1/admin/branches', ['name' => 'Yaba Branch'])->assertCreated();

        $entry = AuditLog::query()->where('action', 'branch.created')->first();

        $this->assertNotNull($entry);
        $this->assertSame($actor->id, $entry->staff_id);
        $this->assertSame('branches', $entry->module);
        $this->assertSame($actor->fullName(), $entry->actor_name);
        // The reference makes the entry meaningful without resolving the record.
        $this->assertMatchesRegularExpression('/^NPBR-\d{3}$/', (string) $entry->auditable_reference);
    }

    #[Test]
    public function a_role_without_the_manage_permission_cannot_create_a_branch(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->postJson('/api/v1/admin/branches', ['name' => 'Unauthorised Branch'])
            ->assertForbidden();

        $this->assertDatabaseMissing('branches', ['name' => 'Unauthorised Branch']);
    }

    #[Test]
    public function a_role_without_the_view_permission_cannot_list_branches(): void
    {
        // Cashier holds no branches.view permission.
        $this->actingAsRole(Role::Cashier);

        $this->getJson('/api/v1/admin/branches')->assertForbidden();
    }

    #[Test]
    public function branches_can_be_searched_filtered_and_sorted(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        Branch::factory()->create(['name' => 'Kano Branch', 'state' => 'Kano']);
        Branch::factory()->create(['name' => 'Aba Branch', 'state' => 'Abia']);
        Branch::factory()->suspended()->create(['name' => 'Jos Branch', 'state' => 'Plateau']);

        $this->getJson('/api/v1/admin/branches?search=Kano')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kano Branch');

        $this->getJson('/api/v1/admin/branches?status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Jos Branch');

        $sorted = $this->getJson('/api/v1/admin/branches?sort=name')->assertOk()->json('data');

        $this->assertSame('Aba Branch', $sorted[0]['name']);
    }

    #[Test]
    public function an_unknown_sort_column_is_ignored_rather_than_honoured(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        Branch::factory()->count(2)->create();

        // Ordering by a column the endpoint never allow-listed must not reach
        // the query builder.
        $this->getJson('/api/v1/admin/branches?sort=email')->assertOk();
    }

    #[Test]
    public function the_options_endpoint_returns_only_active_branches(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        Branch::factory()->create(['name' => 'Active Branch']);
        Branch::factory()->closed()->create(['name' => 'Closed Branch']);

        $options = $this->getJson('/api/v1/admin/branches/options')->assertOk()->json('data');

        $labels = array_column($options, 'label');

        $this->assertTrue(collect($labels)->contains(fn (string $l): bool => str_contains($l, 'Active Branch')));
        $this->assertFalse(collect($labels)->contains(fn (string $l): bool => str_contains($l, 'Closed Branch')));
    }

    #[Test]
    public function a_branch_can_be_updated_and_the_change_is_audited(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create(['name' => 'Old Name', 'city' => 'Lagos']);

        $this->patchJson("/api/v1/admin/branches/{$branch->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $entry = AuditLog::query()->where('action', 'branch.updated')->firstOrFail();

        // Only the field that actually moved is recorded — an entry listing
        // forty unchanged columns buries the one that matters.
        $this->assertSame(['name' => 'Old Name'], $entry->old_values);
        $this->assertSame(['name' => 'New Name'], $entry->new_values);
    }

    #[Test]
    public function a_branch_can_be_suspended_with_a_reason(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $this->postJson("/api/v1/admin/branches/{$branch->id}/status", [
            'status' => 'suspended',
            'reason' => 'Premises closed pending a security review.',
        ])->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertFalse($branch->fresh()->status->acceptsNewBusiness());

        $entry = AuditLog::query()->where('action', 'branch.suspended')->firstOrFail();
        $this->assertSame('Premises closed pending a security review.', $entry->reason);
    }

    #[Test]
    public function a_status_change_requires_a_meaningful_reason(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        // A token character or two would make the audit trail worthless.
        $this->postJson("/api/v1/admin/branches/{$branch->id}/status", [
            'status' => 'suspended',
            'reason' => 'x',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function a_branch_with_staff_assigned_cannot_be_closed(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $branch = Branch::factory()->create();
        Staff::factory()->create(['branch_id' => $branch->id]);

        // Closing it would leave those staff scoped to a branch that accepts no
        // business, quietly losing access to everything.
        $this->postJson("/api/v1/admin/branches/{$branch->id}/status", [
            'status' => 'closed',
            'reason' => 'Consolidating operations into the head office.',
        ])->assertStatus(422);

        $this->assertSame(BranchStatus::Active, $branch->fresh()->status);
    }

    #[Test]
    public function a_branch_with_no_staff_can_be_closed(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $this->postJson("/api/v1/admin/branches/{$branch->id}/status", [
            'status' => 'closed',
            'reason' => 'Consolidating operations into the head office.',
        ])->assertOk();

        $refreshed = $branch->fresh();

        $this->assertSame(BranchStatus::Closed, $refreshed->status);
        $this->assertNotNull($refreshed->closed_at);
        // Closed, never deleted: loans and repayments still reference it.
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'deleted_at' => null]);
    }

    #[Test]
    public function there_is_no_endpoint_to_delete_a_branch(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertStatus(405);
    }

    #[Test]
    public function a_suspended_staff_member_cannot_be_appointed_branch_manager(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $suspended = Staff::factory()->suspended()->create();

        $this->postJson('/api/v1/admin/branches', [
            'name' => 'Managed Branch',
            'manager_id' => $suspended->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function branch_codes_are_unique(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        Branch::factory()->create(['branch_code' => 'NPBR-500']);

        $this->postJson('/api/v1/admin/branches', [
            'name' => 'Duplicate Code Branch',
            'branch_code' => 'NPBR-500',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['branch_code']]);
    }
}
