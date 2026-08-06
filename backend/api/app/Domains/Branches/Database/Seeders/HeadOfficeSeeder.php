<?php

declare(strict_types=1);

namespace App\Domains\Branches\Database\Seeders;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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

        // The branch above is created directly with a hardcoded code rather
        // than through ReferenceGenerator, so its sequence counter is never
        // advanced by that insert. Left alone, the counter starts at 0 and
        // the first branch created through the console is allocated
        // NPBR-001 too, colliding with the seeded head office. GREATEST
        // keeps this idempotent and never moves the counter backwards.
        $now = now();

        DB::statement(
            'INSERT INTO reference_sequences (name, period, current_value, created_at, updated_at)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE current_value = GREATEST(current_value, 1)',
            ['branch', 'ALL', $now, $now],
        );
    }
}
