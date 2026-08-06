<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Enums\StaffStatus;
use App\Domains\Identity\Models\Staff;
use App\Support\Money\Money;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation ----------------------------------------------------------

    #[Test]
    public function an_administrator_can_create_a_staff_account(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'email' => 'amina.yusuf@naitalk.com',
            'job_title' => 'Loan Officer',
            'branch_id' => $branch->id,
            'roles' => [Role::LoanOfficer->value],
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^NPS-\d{5}$/', $response->json('data.staff.staff_number'));
        $this->assertSame('pending_activation', $response->json('data.staff.status'));
        $this->assertSame([Role::LoanOfficer->value], $response->json('data.staff.roles'));
    }

    #[Test]
    public function a_created_account_receives_a_generated_temporary_password_it_must_change(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $response = $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Ifeanyi',
            'last_name' => 'Eze',
            'email' => 'ifeanyi.eze@naitalk.com',
        ])->assertCreated();

        $temporary = $response->json('data.temporary_password');
        $staff = Staff::query()->where('email', 'ifeanyi.eze@naitalk.com')->firstOrFail();

        // Generated rather than chosen by the creating administrator: a
        // password someone else picked is a password someone else knows.
        $this->assertIsString($temporary);
        $this->assertTrue(Hash::check($temporary, $staff->password));
        $this->assertTrue($staff->must_change_password);
    }

    #[Test]
    public function the_super_administrator_role_cannot_be_granted_at_creation(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        // There is exactly one Super Administrator, seeded once at install
        // time — not even an existing one may mint another through staff
        // creation.
        $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Would',
            'last_name' => 'BeAdmin',
            'email' => 'would.beadmin@naitalk.com',
            'branch_id' => $branch->id,
            'roles' => [Role::SuperAdministrator->value],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('staff', ['email' => 'would.beadmin@naitalk.com']);
    }

    #[Test]
    public function a_staff_member_cannot_be_assigned_to_a_closed_branch(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->closed()->create();

        $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Test',
            'last_name' => 'Person',
            'email' => 'test.person@naitalk.com',
            'branch_id' => $branch->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function email_addresses_must_be_unique(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        Staff::factory()->create(['email' => 'taken@naitalk.com']);

        $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Another',
            'last_name' => 'Person',
            'email' => 'taken@naitalk.com',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);
    }

    #[Test]
    public function a_role_without_the_create_permission_cannot_create_staff(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $this->postJson('/api/v1/admin/staff', [
            'first_name' => 'Unauthorised',
            'last_name' => 'Creation',
            'email' => 'nope@naitalk.com',
        ])->assertForbidden();
    }

    // --- Privilege escalation ----------------------------------------------

    #[Test]
    public function a_staff_member_cannot_change_their_own_roles(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        // Self-escalation is the single most valuable move available to a
        // compromised account, so it is refused outright rather than merely
        // permission-gated.
        $this->putJson("/api/v1/admin/staff/{$actor->id}/roles", [
            'roles' => [Role::SuperAdministrator->value],
        ])->assertStatus(422);
    }

    #[Test]
    public function a_staff_member_cannot_raise_their_own_approval_limit(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $this->putJson("/api/v1/admin/staff/{$actor->id}/approval-limit", [
            'approval_limit' => '50000000.00',
            'reason' => 'Attempting to raise my own approval authority.',
        ])->assertStatus(422);

        $this->assertNull($actor->fresh()->approval_limit);
    }

    #[Test]
    public function a_staff_member_cannot_suspend_or_disable_themselves(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson("/api/v1/admin/staff/{$actor->id}/suspend", [
            'reason' => 'Attempting to suspend my own account.',
        ])->assertStatus(422);

        $this->postJson("/api/v1/admin/staff/{$actor->id}/disable", [
            'reason' => 'Attempting to disable my own account.',
        ])->assertStatus(422);

        $this->assertSame(StaffStatus::Active, $actor->fresh()->status);
    }

    #[Test]
    public function only_the_super_administrator_can_assign_roles(): void
    {
        // Operations Manager holds staff.view but not staff.assign_roles.
        $this->actingAsRole(Role::OperationsManager);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/roles", [
            'roles' => [Role::FinanceManager->value],
        ])->assertForbidden();

        $this->assertCount(0, $subject->fresh()->roleNames());
    }

    #[Test]
    public function only_the_super_administrator_can_set_an_approval_limit(): void
    {
        $this->actingAsRole(Role::OperationsManager);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '1000000.00',
            'reason' => 'Granting approval authority for branch operations.',
        ])->assertForbidden();
    }

    #[Test]
    public function assigning_roles_revokes_the_subjects_existing_sessions(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $subject = Staff::factory()->create();
        $subject->createToken('test', ['merchants.view']);

        $this->assertSame(1, $subject->tokens()->count());

        $this->putJson("/api/v1/admin/staff/{$subject->id}/roles", [
            'roles' => [Role::Cashier->value],
        ])->assertOk();

        // Existing tokens carry the old permission set as abilities, so they
        // must not survive a role change.
        $this->assertSame(0, $subject->tokens()->count());
    }

    #[Test]
    public function an_existing_super_administrator_cannot_grant_the_role_to_someone_else(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $subject = Staff::factory()->create();
        $subject->assignRole(Role::Cashier->value);

        $this->putJson("/api/v1/admin/staff/{$subject->id}/roles", [
            'roles' => [Role::SuperAdministrator->value],
        ])->assertStatus(422);

        $this->assertSame([Role::Cashier->value], $subject->fresh()->roleNames()->all());
    }

    #[Test]
    public function a_role_change_is_audited_with_the_before_and_after(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $subject = Staff::factory()->create();
        $subject->assignRole(Role::Cashier->value);

        $this->putJson("/api/v1/admin/staff/{$subject->id}/roles", [
            'roles' => [Role::FinanceOfficer->value],
        ])->assertOk();

        $entry = AuditLog::query()->where('action', 'staff.roles_changed')->firstOrFail();

        $this->assertSame([Role::Cashier->value], $entry->old_values['roles']);
        $this->assertSame([Role::FinanceOfficer->value], $entry->new_values['roles']);
        $this->assertSame($actor->id, $entry->staff_id);
    }

    // --- Approval limits ---------------------------------------------------

    #[Test]
    public function an_approval_limit_is_stored_and_compared_exactly(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '2500000.50',
            'reason' => 'Branch manager approval authority for the Lagos region.',
        ])->assertOk()->assertJsonPath('data.approval_limit.amount', '2500000.50');

        $refreshed = $subject->fresh();

        // Compared as exact decimals, never floats: an approval limit that
        // drifts by a kobo either blocks a legitimate approval or permits one
        // beyond authority.
        $this->assertTrue($refreshed->canApproveAmount(Money::fromDecimal('2500000.50')));
        $this->assertTrue($refreshed->canApproveAmount(Money::fromDecimal('2500000.49')));
        $this->assertFalse($refreshed->canApproveAmount(Money::fromDecimal('2500000.51')));
    }

    #[Test]
    public function approval_authority_can_be_removed_entirely(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '100000.00',
            'reason' => 'Initial approval authority for the credit team.',
        ])->assertOk();

        $this->assertTrue($subject->fresh()->hasApprovalAuthority());

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => null,
            'reason' => 'Approval authority withdrawn pending a review.',
        ])->assertOk();

        $refreshed = $subject->fresh();

        // Null is distinct from zero: no authority at all, rather than a
        // deliberate limit of nothing.
        $this->assertNull($refreshed->approval_limit);
        $this->assertFalse($refreshed->hasApprovalAuthority());
        $this->assertFalse($refreshed->canApproveAmount(Money::zero()));
    }

    #[Test]
    public function a_zero_approval_limit_is_distinct_from_no_authority(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '0.00',
            'reason' => 'Holds the role but approves nothing during probation.',
        ])->assertOk();

        $refreshed = $subject->fresh();

        $this->assertTrue($refreshed->hasApprovalAuthority());
        $this->assertTrue($refreshed->canApproveAmount(Money::zero()));
        $this->assertFalse($refreshed->canApproveAmount(Money::fromDecimal('0.01')));
    }

    #[Test]
    public function a_malformed_approval_limit_is_refused(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        foreach (['1,000.00', '100.005', 'abc', '-500.00'] as $invalid) {
            $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
                'approval_limit' => $invalid,
                'reason' => 'Testing an invalid approval limit value.',
            ])->assertStatus(422);
        }
    }

    #[Test]
    public function an_approval_limit_change_requires_a_reason_and_is_audited(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '750000.00',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);

        $this->putJson("/api/v1/admin/staff/{$subject->id}/approval-limit", [
            'approval_limit' => '750000.00',
            'reason' => 'Promoted to Credit Manager for the northern region.',
        ])->assertOk();

        $entry = AuditLog::query()->where('action', 'staff.approval_limit_changed')->firstOrFail();

        $this->assertSame('Promoted to Credit Manager for the northern region.', $entry->reason);
        $this->assertNull($entry->old_values['approval_limit']);
        $this->assertSame('750000.00', $entry->new_values['approval_limit']);
    }

    // --- Transfers and status ----------------------------------------------

    #[Test]
    public function a_staff_member_can_be_transferred_between_branches(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $from = Branch::factory()->create(['name' => 'Ibadan Branch']);
        $to = Branch::factory()->create(['name' => 'Abuja Branch']);
        $subject = Staff::factory()->create(['branch_id' => $from->id]);

        $this->postJson("/api/v1/admin/staff/{$subject->id}/transfer", [
            'branch_id' => $to->id,
            'reason' => 'Reassigned to support the Abuja branch expansion.',
        ])->assertOk()->assertJsonPath('data.branch.name', 'Abuja Branch');

        $this->assertSame($to->id, $subject->fresh()->branch_id);

        // Recorded as its own action: a transfer changes what someone can see.
        $entry = AuditLog::query()->where('action', 'staff.transferred')->firstOrFail();
        $this->assertSame($from->id, $entry->old_values['branch_id']);
        $this->assertSame($to->id, $entry->new_values['branch_id']);
    }

    #[Test]
    public function suspending_a_staff_member_cuts_every_session_immediately(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $subject = Staff::factory()->create();
        $token = $this->signIn($subject, StaffFactory::PASSWORD);

        $this->postJson("/api/v1/admin/staff/{$subject->id}/suspend", [
            'reason' => 'Suspended pending an internal investigation.',
        ])->assertOk();

        $refreshed = $subject->fresh();

        $this->assertSame(StaffStatus::Suspended, $refreshed->status);
        $this->assertSame('Suspended pending an internal investigation.', $refreshed->suspension_reason);

        // Status alone would only stop the next sign-in; the live bearer token
        // would keep working until it expired.
        $this->assertSame(0, $subject->tokens()->count());
        $this->tokenRequest($token, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
    }

    #[Test]
    public function a_suspended_staff_member_can_be_reinstated(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->suspended()->create();

        $this->postJson("/api/v1/admin/staff/{$subject->id}/reinstate", [
            'reason' => 'Investigation concluded with no findings.',
        ])->assertOk();

        $refreshed = $subject->fresh();

        $this->assertSame(StaffStatus::Active, $refreshed->status);
        $this->assertNull($refreshed->suspension_reason);
    }

    #[Test]
    public function the_last_active_super_administrator_cannot_be_disabled(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $other = Staff::factory()->create();
        $other->assignRole(Role::SuperAdministrator->value);

        // Two exist, so one can go.
        $this->postJson("/api/v1/admin/staff/{$other->id}/disable", [
            'reason' => 'Left the organisation at the end of the month.',
        ])->assertOk();

        // The actor is now the only one, and cannot disable themselves anyway —
        // so create a third to attempt the disable from.
        $deputy = Staff::factory()->create();
        $deputy->assignRole(Role::SuperAdministrator->value);
        $deputy->forceFill([
            'two_factor_secret' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Now disable the deputy, leaving only the actor.
        $this->postJson("/api/v1/admin/staff/{$deputy->id}/disable", [
            'reason' => 'Temporary cover assignment has ended.',
        ])->assertOk();

        $lastAdmin = Staff::factory()->create();
        $lastAdmin->assignRole(Role::SuperAdministrator->value);

        // Disable the actor's peer, leaving lastAdmin and actor... then reduce
        // to a single active Super Administrator and confirm it is protected.
        Staff::query()->whereKeyNot($lastAdmin->id)->whereKeyNot($actor->id)
            ->update(['status' => StaffStatus::Disabled->value]);
        $actor->forceFill(['status' => StaffStatus::Disabled->value])->save();

        $this->postJson("/api/v1/admin/staff/{$lastAdmin->id}/disable", [
            'reason' => 'Attempting to remove the only remaining administrator.',
        ])->assertStatus(422);

        $this->assertSame(StaffStatus::Active, $lastAdmin->fresh()->status);
    }

    #[Test]
    public function a_status_change_requires_a_meaningful_reason(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        $this->postJson("/api/v1/admin/staff/{$subject->id}/suspend", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    // --- Listing and scoping -----------------------------------------------

    #[Test]
    public function a_branch_scoped_administrator_sees_only_their_own_branch(): void
    {
        $lagos = Branch::factory()->create(['name' => 'Lagos Branch']);
        $kano = Branch::factory()->create(['name' => 'Kano Branch']);

        $actor = $this->actingAsRole(Role::OperationsManager, [
            'branch_id' => $lagos->id,
            'access_scope' => 'branch',
        ]);

        Staff::factory()->create(['branch_id' => $lagos->id, 'last_name' => 'Lagos Colleague']);
        Staff::factory()->create(['branch_id' => $kano->id, 'last_name' => 'Kano Colleague']);

        $names = collect($this->getJson('/api/v1/admin/staff')->assertOk()->json('data'))
            ->pluck('last_name');

        $this->assertTrue($names->contains('Lagos Colleague'));
        $this->assertFalse($names->contains('Kano Colleague'));
        // They can always see themselves.
        $this->assertTrue($names->contains($actor->last_name));
    }

    #[Test]
    public function a_globally_scoped_administrator_sees_every_branch(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        Staff::factory()->create(['branch_id' => $lagos->id, 'last_name' => 'Lagos Colleague']);
        Staff::factory()->create(['branch_id' => $kano->id, 'last_name' => 'Kano Colleague']);

        $names = collect($this->getJson('/api/v1/admin/staff')->assertOk()->json('data'))
            ->pluck('last_name');

        $this->assertTrue($names->contains('Lagos Colleague'));
        $this->assertTrue($names->contains('Kano Colleague'));
    }

    #[Test]
    public function the_staff_list_never_exposes_credentials(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        Staff::factory()->withTwoFactor()->create();

        $body = (string) $this->getJson('/api/v1/admin/staff')->assertOk()->getContent();

        $this->assertStringNotContainsString('two_factor_secret', $body);
        $this->assertStringNotContainsString('"password"', $body);
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $body);
    }

    #[Test]
    public function an_administrator_can_issue_a_temporary_password(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();
        $this->signIn($subject, StaffFactory::PASSWORD);

        $temporary = $this->postJson("/api/v1/admin/staff/{$subject->id}/reset-password")
            ->assertOk()
            ->json('data.temporary_password');

        $refreshed = $subject->fresh();

        $this->assertTrue(Hash::check($temporary, $refreshed->password));
        $this->assertTrue($refreshed->must_change_password);
        // Every session is cut — an administrator resetting a password is
        // usually responding to a suspected compromise.
        $this->assertSame(0, $subject->tokens()->count());

        // The password itself is never written to the audit trail.
        $entry = AuditLog::query()->where('action', 'staff.password_reset_by_administrator')->firstOrFail();
        $this->assertStringNotContainsString($temporary, $entry->toJson());
    }
}
