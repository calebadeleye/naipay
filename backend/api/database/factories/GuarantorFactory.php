<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\LoanApplications\Models\Guarantor;
use App\Domains\LoanApplications\Models\LoanApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guarantor>
 */
final class GuarantorFactory extends Factory
{
    protected $model = Guarantor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_application_id' => LoanApplication::factory(),
            'full_name' => fake()->name(),
            'phone' => '080'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'relationship' => fake()->randomElement(['Spouse', 'Sibling', 'Business partner', 'Friend']),
            'address' => fake()->streetAddress(),
            'id_type' => 'National ID',
            'id_number' => fake()->numerify('###########'),
            'employer' => fake()->company(),
            'occupation' => fake()->jobTitle(),
            'monthly_income' => '200000.00',
        ];
    }
}
