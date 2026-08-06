<?php

declare(strict_types=1);

namespace App\Domains\Investors\Database\Seeders;

use App\Domains\Investors\Enums\InvestorStatus;
use App\Domains\Investors\Models\Investor;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a first investor account, so the investor portal can be signed
 * into on a fresh installation. Mirrors SuperAdministratorSeeder: the
 * password comes from NAIPAY_INITIAL_INVESTOR_PASSWORD when set, and is
 * otherwise generated and printed once to the console.
 */
final class InvestorSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower((string) env('NAIPAY_INITIAL_INVESTOR_EMAIL', 'investor@naitalk.com'));

        if (Investor::query()->where('email', $email)->exists()) {
            $this->command?->info("Investor [{$email}] already exists — skipping.");

            return;
        }

        $password = (string) env('NAIPAY_INITIAL_INVESTOR_PASSWORD', '');
        $generated = $password === '';

        if ($generated) {
            $password = Str::random(20).'aA1!';
        }

        $investor = DB::transaction(function () use ($email, $password): Investor {
            $investor = new Investor([
                'investor_number' => app(ReferenceGenerator::class)->next('investor'),
                'name' => (string) env('NAIPAY_INITIAL_INVESTOR_NAME', 'Demo Investor'),
                'email' => $email,
                'status' => InvestorStatus::Active,
            ]);

            $investor->forceFill(['password' => $password])->save();

            return $investor;
        });

        $this->command?->newLine();
        $this->command?->info('Investor created.');
        $this->command?->line("  Email:  {$investor->email}");

        if ($generated) {
            $this->command?->line("  Password: {$password}");
            $this->command?->warn('  This password is shown once.');
        }

        $this->command?->newLine();
    }
}
