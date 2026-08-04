<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->randomElement([
            'Lagos Mainland', 'Abuja', 'Ibadan', 'Kano', 'Port Harcourt',
            'Enugu', 'Kaduna', 'Benin City', 'Aba', 'Jos',
        ]);

        return [
            'branch_code' => 'NPBR-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => $city.' Branch',
            'address' => fake()->streetAddress(),
            'city' => $city,
            'state' => fake()->randomElement(['Lagos', 'FCT', 'Oyo', 'Kano', 'Rivers', 'Enugu']),
            'country' => 'Nigeria',
            'phone' => '080'.fake()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'status' => BranchStatus::Active,
            'opened_at' => fake()->dateTimeBetween('-5 years', '-1 month'),
        ];
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => BranchStatus::Suspended]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => BranchStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
