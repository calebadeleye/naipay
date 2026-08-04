<?php

declare(strict_types=1);

namespace App\Domains\Identity\Database\Seeders;

use App\Domains\Identity\Enums\Role as RoleEnum;
use App\Domains\Identity\Enums\StaffStatus;
use App\Domains\Identity\Models\Staff;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the first Super Administrator, so a fresh installation can be signed
 * into at all.
 *
 * The password is never hard-coded. It is taken from NAIPAY_INITIAL_ADMIN_PASSWORD
 * when set, and otherwise generated and printed once to the console. Either
 * way the account is flagged `must_change_password`, so whatever this seeder
 * knows stops being valid at first sign-in.
 *
 * Two-factor is mandatory for this role, so the account cannot do anything
 * beyond enrolling until it is set up.
 */
final class SuperAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower((string) env('NAIPAY_INITIAL_ADMIN_EMAIL', 'admin@naitalk.com'));

        if (Staff::query()->where('email', $email)->exists()) {
            $this->command?->info("Super Administrator [{$email}] already exists — skipping.");

            return;
        }

        $password = (string) env('NAIPAY_INITIAL_ADMIN_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            // Mixed case, digits and symbols, to satisfy the staff password
            // policy without a retry loop.
            $password = Str::random(20).'aA1!';
        }

        $staff = DB::transaction(function () use ($email, $password): Staff {
            $staff = new Staff([
                'staff_number' => app(ReferenceGenerator::class)->next('staff'),
                'first_name' => (string) env('NAIPAY_INITIAL_ADMIN_FIRST_NAME', 'Naipay'),
                'last_name' => (string) env('NAIPAY_INITIAL_ADMIN_LAST_NAME', 'Administrator'),
                'email' => $email,
                'job_title' => 'Super Administrator',
                'status' => StaffStatus::Active,
            ]);

            $staff->forceFill([
                'password' => $password,
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();

            $staff->assignRole(RoleEnum::SuperAdministrator->value);

            return $staff;
        });

        $this->command?->newLine();
        $this->command?->info('Super Administrator created.');
        $this->command?->line("  Email:  {$staff->email}");

        if ($generated) {
            $this->command?->line("  Password: {$password}");
            $this->command?->warn('  This password is shown once. It must be changed at first sign-in.');
        }

        $this->command?->warn('  Two-factor authentication is mandatory for this role and must be set up before use.');
        $this->command?->newLine();
    }
}
