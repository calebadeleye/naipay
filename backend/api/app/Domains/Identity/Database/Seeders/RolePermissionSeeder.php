<?php

declare(strict_types=1);

namespace App\Domains\Identity\Database\Seeders;

use App\Domains\Identity\Enums\Permission as PermissionEnum;
use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Support\RolePermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every role and permission, and reconciles the grants between them.
 *
 * Safe to run repeatedly, and intended to be: it runs on every deploy so that
 * a permission added in code exists in the database without anyone having to
 * remember. Grants are synced rather than merely added, so a permission
 * removed from the matrix is actually revoked — otherwise access would only
 * ever accumulate.
 */
final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = (string) config('auth.defaults.guard', 'staff');

        DB::transaction(function () use ($guard): void {
            $this->seedPermissions($guard);
            $this->seedRoles($guard);
            $this->syncGrants($guard);
        });

        // The registrar caches the permission map; without this the changes
        // above are invisible for the rest of the process.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedPermissions(string $guard): void
    {
        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, $guard);
        }
    }

    private function seedRoles(string $guard): void
    {
        foreach (RoleEnum::cases() as $role) {
            Role::findOrCreate($role->value, $guard);
        }
    }

    private function syncGrants(string $guard): void
    {
        foreach (RolePermissionMatrix::toArray() as $roleName => $permissionNames) {
            $role = Role::findByName($roleName, $guard);

            $role->syncPermissions($permissionNames);
        }
    }
}
