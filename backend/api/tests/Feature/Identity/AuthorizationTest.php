<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Support\RolePermissionMatrix;
use Database\Factories\StaffFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/**
 * Asserts the role and permission matrix, with particular attention to
 * segregation of duties.
 *
 * These are the controls an auditor tests. Getting them wrong does not produce
 * an error anyone notices — it produces a system where one person can originate,
 * approve and disburse a loan to themselves.
 */
final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    #[Test]
    public function every_role_is_seeded(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertDatabaseHas('roles', [
                'name' => $role->value,
                'guard_name' => 'staff',
            ]);
        }

        $this->assertSame(15, RoleModel::query()->count());
    }

    #[Test]
    public function every_permission_is_seeded(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertDatabaseHas('permissions', [
                'name' => $permission->value,
                'guard_name' => 'staff',
            ]);
        }

        $this->assertSame(count(Permission::cases()), PermissionModel::query()->count());
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $rolesBefore = RoleModel::query()->count();
        $permissionsBefore = PermissionModel::query()->count();

        // It runs on every deploy, so a second run must change nothing.
        $this->seedRolesAndPermissions();

        $this->assertSame($rolesBefore, RoleModel::query()->count());
        $this->assertSame($permissionsBefore, PermissionModel::query()->count());
    }

    #[Test]
    public function revoked_grants_are_actually_removed_on_reseed(): void
    {
        $role = RoleModel::findByName(Role::Cashier->value, 'staff');

        // Simulate a grant that the matrix no longer contains.
        $role->givePermissionTo(Permission::LedgerPost->value);
        $this->assertTrue($role->fresh()->hasPermissionTo(Permission::LedgerPost->value));

        $this->seedRolesAndPermissions();

        // Grants are synced, not merely added — otherwise access would only
        // ever accumulate.
        $this->assertFalse(
            RoleModel::findByName(Role::Cashier->value, 'staff')->hasPermissionTo(Permission::LedgerPost->value)
        );
    }

    #[Test]
    public function the_super_administrator_holds_every_permission(): void
    {
        $staff = Staff::factory()->create();
        $staff->assignRole(Role::SuperAdministrator->value);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $staff->hasPermissionTo($permission->value),
                "Super Administrator is missing [{$permission->value}]."
            );
        }
    }

    // --- Segregation of duties ---------------------------------------------

    #[Test]
    #[DataProvider('segregationScenarios')]
    public function no_role_holds_both_halves_of_a_financial_control(
        Role $role,
        Permission $maker,
        Permission $checker,
    ): void {
        $staff = Staff::factory()->create();
        $staff->assignRole($role->value);

        $holdsBoth = $staff->hasPermissionTo($maker->value) && $staff->hasPermissionTo($checker->value);

        $this->assertFalse(
            $holdsBoth,
            "[{$role->value}] holds both [{$maker->value}] and [{$checker->value}], "
            .'which lets one person complete both halves of the control.'
        );
    }

    /**
     * @return array<string, array{Role, Permission, Permission}>
     */
    public static function segregationScenarios(): array
    {
        return [
            'a cashier cannot record and approve a repayment' => [
                Role::Cashier, Permission::RepaymentsRecord, Permission::RepaymentsApprove,
            ],
            'a finance officer cannot verify and approve a repayment' => [
                Role::FinanceOfficer, Permission::RepaymentsVerify, Permission::RepaymentsApprove,
            ],
            'a credit officer cannot recommend and approve a loan' => [
                Role::CreditOfficer, Permission::LoanApplicationsRecommend, Permission::LoanApplicationsApprove,
            ],
            'a loan officer cannot originate and approve a loan' => [
                Role::LoanOfficer, Permission::LoanApplicationsCreate, Permission::LoanApplicationsApprove,
            ],
            'a credit manager cannot approve and disburse a loan' => [
                Role::CreditManager, Permission::LoansApprove, Permission::LoansDisburse,
            ],
            'a collections officer cannot record and approve a repayment' => [
                Role::CollectionsOfficer, Permission::RepaymentsRecord, Permission::RepaymentsApprove,
            ],
        ];
    }

    #[Test]
    public function only_the_super_administrator_can_change_access_control(): void
    {
        foreach (Role::cases() as $role) {
            if ($role === Role::SuperAdministrator) {
                continue;
            }

            $staff = Staff::factory()->create();
            $staff->assignRole($role->value);

            foreach ([Permission::RolesManage, Permission::StaffAssignRoles, Permission::SettingsManage] as $permission) {
                $this->assertFalse(
                    $staff->hasPermissionTo($permission->value),
                    "[{$role->value}] should not hold [{$permission->value}]."
                );
            }
        }
    }

    #[Test]
    public function read_only_roles_hold_no_write_permission(): void
    {
        $writePermissions = [
            Permission::MerchantsCreate,
            Permission::MerchantsUpdate,
            Permission::LoansApprove,
            Permission::LoansDisburse,
            Permission::RepaymentsRecord,
            Permission::RepaymentsApprove,
            Permission::LedgerPost,
            Permission::LedgerReverse,
            Permission::SettingsManage,
        ];

        foreach ([Role::Auditor, Role::ReadOnlyUser, Role::Executive, Role::CustomerSupport] as $role) {
            $staff = Staff::factory()->create();
            $staff->assignRole($role->value);

            foreach ($writePermissions as $permission) {
                $this->assertFalse(
                    $staff->hasPermissionTo($permission->value),
                    "[{$role->value}] should not hold write permission [{$permission->value}]."
                );
            }
        }
    }

    #[Test]
    public function the_audit_trail_is_writable_by_nobody(): void
    {
        // There is no audit.create, audit.update or audit.delete permission at
        // all — the trail is append-only by construction, not by policy.
        $auditPermissions = array_filter(
            Permission::values(),
            static fn (string $value): bool => str_starts_with($value, 'audit.'),
        );

        $this->assertSame(
            ['audit.view', 'audit.export'],
            array_values($auditPermissions),
        );
    }

    #[Test]
    public function unmasked_identity_numbers_are_held_by_very_few_roles(): void
    {
        $rolesWithAccess = [];

        foreach (Role::cases() as $role) {
            $permissions = RolePermissionMatrix::permissionsFor($role);

            if (in_array(Permission::MerchantsViewSensitive, $permissions, true)) {
                $rolesWithAccess[] = $role->value;
            }
        }

        // Compliance verifies identity documents and Credit assesses risk;
        // the Super Administrator holds everything. Nobody else needs a full
        // BVN or NIN.
        $this->assertEqualsCanonicalizing(
            [
                Role::SuperAdministrator->value,
                Role::CreditManager->value,
                Role::ComplianceOfficer->value,
            ],
            $rolesWithAccess,
        );
    }

    // --- Mandatory two-factor ----------------------------------------------

    #[Test]
    public function roles_holding_privileged_permissions_must_use_two_factor(): void
    {
        foreach (Role::requiringTwoFactor() as $role) {
            $staff = Staff::factory()->create();
            $staff->assignRole($role->value);

            $this->assertTrue(
                $staff->fresh()->requiresTwoFactor(),
                "[{$role->value}] must require two-factor authentication."
            );
        }
    }

    #[Test]
    public function a_bespoke_role_carrying_a_privileged_permission_also_requires_two_factor(): void
    {
        // The requirement is driven by permissions held, not only by the
        // configured role list, so a new role granting ledger access is
        // covered without anyone remembering to list it.
        $staff = Staff::factory()->create();
        $staff->givePermissionTo(Permission::LedgerPost->value);

        $this->assertTrue($staff->fresh()->requiresTwoFactor());
    }

    #[Test]
    public function an_unprivileged_role_does_not_require_two_factor(): void
    {
        foreach ([Role::Cashier, Role::CustomerSupport, Role::ReadOnlyUser, Role::LoanOfficer] as $role) {
            $staff = Staff::factory()->create();
            $staff->assignRole($role->value);

            $this->assertFalse(
                $staff->fresh()->requiresTwoFactor(),
                "[{$role->value}] should not mandate two-factor authentication."
            );
        }
    }

    // --- Token abilities ---------------------------------------------------

    #[Test]
    public function an_issued_token_carries_the_holders_permissions_as_abilities(): void
    {
        $staff = Staff::factory()->create();
        $staff->assignRole(Role::Cashier->value);

        $this->signIn($staff, StaffFactory::PASSWORD);

        $abilities = $staff->tokens()->first()->abilities;

        $this->assertContains(Permission::RepaymentsRecord->value, $abilities);
        $this->assertNotContains(Permission::RepaymentsApprove->value, $abilities);
    }
}
