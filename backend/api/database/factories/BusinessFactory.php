<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Enums\BusinessType;
use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
final class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_number' => 'NPB-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'merchant_id' => Merchant::factory(),
            'business_category_id' => BusinessCategory::factory(),
            'business_name' => fake()->company(),
            'business_type' => BusinessType::SoleProprietorship,
            'business_phone' => '080'.fake()->numerify('########'),
            'business_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'year_established' => fake()->numberBetween(2000, 2025),
            'number_of_employees' => fake()->numberBetween(1, 40),
            'estimated_monthly_revenue' => '850000.00',
            'estimated_monthly_expenses' => '520000.00',
            'status' => BusinessStatus::Inactive,
            'verification_status' => VerificationStatus::Unverified,
        ];
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verification_status' => VerificationStatus::Verified,
            'status' => BusinessStatus::Active,
            'approved_at' => now(),
        ]);
    }
}
