<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Branches\Database\Seeders\HeadOfficeSeeder;
use App\Domains\Identity\Database\Seeders\RolePermissionSeeder;
use App\Domains\Identity\Database\Seeders\SuperAdministratorSeeder;
use Illuminate\Database\Seeder;

/**
 * Reference data required for Naipay to function.
 *
 * Everything here is idempotent and safe to run on every deploy — roles,
 * permissions, and later business categories, document types and the chart of
 * accounts are configuration, not sample data.
 *
 * Development fixtures (merchants, loans, repayments) belong in a separate
 * seeder that never runs in production.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            HeadOfficeSeeder::class,
            SuperAdministratorSeeder::class,
        ]);
    }
}
