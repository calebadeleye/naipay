<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Investors\Enums\InvestorStatus;
use App\Domains\Investors\Models\Investor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Investor>
 */
final class InvestorFactory extends Factory
{
    /**
     * The password every generated account shares, so tests can sign in
     * without each one inventing its own.
     */
    public const PASSWORD = 'Naipay-Test-Pass1!';

    protected $model = Investor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_number' => 'NPI-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '080'.fake()->numerify('########'),
            'password' => self::PASSWORD,
            'status' => InvestorStatus::Active,
            'failed_login_attempts' => 0,
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => InvestorStatus::Suspended]);
    }

    public function disabled(): self
    {
        return $this->state(fn (): array => ['status' => InvestorStatus::Disabled]);
    }
}
