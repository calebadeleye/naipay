<?php

declare(strict_types=1);

namespace App\Domains\Branches\Database\Seeders;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Creates the head office.
 *
 * A branch has to exist before any staff member can be assigned one, and an
 * installation with no branch at all would leave every branch-scoped account
 * seeing nothing. Idempotent, like all reference-data seeders.
 */
final class HeadOfficeSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['branch_code' => 'NPBR-001'],
            [
                'name' => (string) env('NAIPAY_HEAD_OFFICE_NAME', 'Head Office'),
                'city' => (string) env('NAIPAY_HEAD_OFFICE_CITY', 'Lagos'),
                'state' => (string) env('NAIPAY_HEAD_OFFICE_STATE', 'Lagos'),
                'country' => 'Nigeria',
                'status' => BranchStatus::Active,
                'opened_at' => now()->toDateString(),
            ],
        );
    }
}
