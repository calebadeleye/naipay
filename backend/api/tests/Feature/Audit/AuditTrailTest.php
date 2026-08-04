<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Support\Correlation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_audit_entry_cannot_be_modified(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $entry = app(AuditLogger::class)->recordCreation('branch.created', 'branches', $branch);

        // Enforced at the model rather than left to discipline. A trail that
        // can be quietly edited is not a trail.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $entry->update(['action' => 'something.else']);
    }

    #[Test]
    public function an_audit_entry_cannot_be_deleted(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $entry = app(AuditLogger::class)->recordCreation('branch.created', 'branches', $branch);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permanent');

        $entry->delete();
    }

    #[Test]
    public function no_permission_to_write_the_audit_trail_exists(): void
    {
        // The trail is append-only by construction, not by policy: there is no
        // audit.create, audit.update or audit.delete permission to grant.
        $this->seedRolesAndPermissions();

        $this->assertDatabaseMissing('permissions', ['name' => 'audit.create']);
        $this->assertDatabaseMissing('permissions', ['name' => 'audit.update']);
        $this->assertDatabaseMissing('permissions', ['name' => 'audit.delete']);
    }

    #[Test]
    public function an_entry_captures_the_actor_and_the_request_context(): void
    {
        $actor = $this->actingAsRole(Role::SuperAdministrator);

        $this->postJson('/api/v1/admin/branches', ['name' => 'Audited Branch'])->assertCreated();

        $entry = AuditLog::query()->where('action', 'branch.created')->firstOrFail();

        $this->assertSame($actor->id, $entry->staff_id);
        $this->assertSame($actor->fullName(), $entry->actor_name);
        // Roles are denormalised so the entry still explains what authority the
        // actor held, even after their roles later change.
        $this->assertStringContainsString('super-administrator', (string) $entry->actor_roles);
        $this->assertNotNull($entry->ip_address);
        $this->assertNotNull($entry->correlation_id);
    }

    #[Test]
    public function entries_share_the_correlation_id_of_the_request_that_produced_them(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $response = $this->withHeaders(['X-Correlation-Id' => 'admin-web-abc123'])
            ->postJson('/api/v1/admin/branches', ['name' => 'Correlated Branch'])
            ->assertCreated();

        $this->assertSame('admin-web-abc123', $response->headers->get('X-Correlation-Id'));

        $entry = AuditLog::query()->where('action', 'branch.created')->firstOrFail();

        // This is what makes "what else happened as part of this action?"
        // answerable after the fact.
        $this->assertSame('admin-web-abc123', $entry->correlation_id);
    }

    #[Test]
    public function sensitive_values_are_recorded_as_changed_rather_than_captured(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $subject = Staff::factory()->create();

        app(AuditLogger::class)->record(
            action: 'merchant.sensitive_update',
            module: 'merchants',
            subject: $subject,
            oldValues: ['bvn' => '22123456714', 'city' => 'Lagos'],
            newValues: ['bvn' => '22987654321', 'city' => 'Abuja'],
        );

        $entry = AuditLog::query()->where('action', 'merchant.sensitive_update')->firstOrFail();

        // An auditor needs to know a BVN was edited and by whom, never what it
        // was changed to.
        $this->assertSame('[changed]', $entry->old_values['bvn']);
        $this->assertSame('[changed]', $entry->new_values['bvn']);
        $this->assertStringNotContainsString('22123456714', $entry->toJson());
        $this->assertStringNotContainsString('22987654321', $entry->toJson());

        // Non-sensitive fields are recorded in full.
        $this->assertSame('Lagos', $entry->old_values['city']);
        $this->assertSame('Abuja', $entry->new_values['city']);
    }

    #[Test]
    public function only_changed_attributes_are_recorded(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);

        $branch = Branch::factory()->create(['name' => 'Before', 'city' => 'Lagos']);
        $before = $branch->getAttributes();

        $branch->name = 'After';
        $branch->save();

        $entry = app(AuditLogger::class)->recordChange('branch.updated', 'branches', $branch, $before);

        $this->assertSame(['name'], array_keys($entry->new_values));
        // Timestamps move on every save and say nothing about intent.
        $this->assertArrayNotHasKey('updated_at', $entry->new_values);
    }

    #[Test]
    public function a_system_action_is_recorded_without_an_actor(): void
    {
        $branch = Branch::factory()->create();

        Correlation::set('00000000-0000-4000-8000-000000000000');

        $entry = app(AuditLogger::class)->record(
            action: 'branch.reviewed',
            module: 'branches',
            subject: $branch,
        );

        // Scheduled and queued work legitimately has no actor; the entry is
        // still written rather than skipped.
        $this->assertNull($entry->staff_id);
        $this->assertNull($entry->actor_name);
        $this->assertSame('00000000-0000-4000-8000-000000000000', $entry->correlation_id);
    }

    #[Test]
    public function the_change_comparison_pairs_old_and_new_values(): void
    {
        $this->actingAsRole(Role::SuperAdministrator);
        $branch = Branch::factory()->create();

        $entry = app(AuditLogger::class)->record(
            action: 'branch.updated',
            module: 'branches',
            subject: $branch,
            oldValues: ['name' => 'Old', 'city' => 'Lagos'],
            newValues: ['name' => 'New', 'state' => 'Kano'],
        );

        $this->assertSame([
            'name' => ['old' => 'Old', 'new' => 'New'],
            'city' => ['old' => 'Lagos', 'new' => null],
            'state' => ['old' => null, 'new' => 'Kano'],
        ], $entry->changes());
    }
}
