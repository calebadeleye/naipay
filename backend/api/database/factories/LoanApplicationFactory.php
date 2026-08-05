<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanApplication>
 */
final class LoanApplicationFactory extends Factory
{
    protected $model = LoanApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_number' => 'NPA-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),

            // Eligible to borrow by default, so a caller only needs to
            // override this when the test is specifically about eligibility.
            'merchant_id' => Merchant::factory()->approved()->state([
                'merchant_status' => MerchantStatus::Active,
            ]),

            'business_id' => function (array $attributes) {
                return Business::factory()->verified()->create([
                    'merchant_id' => $attributes['merchant_id'],
                ])->id;
            },

            'loan_product_id' => LoanProduct::factory(),

            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
            'purpose' => 'Working capital for inventory restock.',

            'status' => LoanApplicationStatus::Draft,
            'created_by' => Staff::factory(),
        ];
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => [
            'status' => LoanApplicationStatus::Submitted,
            'submitted_at' => now(),
            'expires_at' => now()->addDays(60),
        ]);
    }

    public function underAssessment(): self
    {
        return $this->submitted()->state(fn (): array => [
            'status' => LoanApplicationStatus::UnderAssessment,
            'assessment_notes' => 'Trading activity confirmed against bank statements.',
            'assessed_by' => Staff::factory(),
            'assessed_at' => now(),
        ]);
    }

    public function recommended(): self
    {
        return $this->underAssessment()->state(fn (): array => [
            'status' => LoanApplicationStatus::Recommended,
            'recommendation_notes' => 'Recommended for approval at the requested amount.',
            'recommended_by' => Staff::factory(),
            'recommended_at' => now(),
        ]);
    }

    public function approved(): self
    {
        return $this->recommended()->state(fn (array $attributes): array => [
            'status' => LoanApplicationStatus::Approved,
            'approved_amount' => $attributes['requested_amount'] ?? '150000.00',
            'approved_tenor' => $attributes['requested_tenor'] ?? 20,
            'approved_interest_rate' => '20.0000',
            'approved_by' => Staff::factory(),
            'approved_at' => now(),
        ]);
    }
}
