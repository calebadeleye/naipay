<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\LoanProducts\Enums\FeeType;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Enums\TenorUnit;
use App\Domains\LoanProducts\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanProduct>
 */
final class LoanProductFactory extends Factory
{
    protected $model = LoanProduct::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'NPD-'.fake()->unique()->numerify('####'),
            'name' => 'Test Product',
            'minimum_amount' => '10000.00',
            'maximum_amount' => '5000000.00',
            'minimum_tenor' => 10,
            'maximum_tenor' => 120,
            'default_tenor' => 20,
            'tenor_unit' => TenorUnit::Days,
            'interest_method' => InterestMethod::Flat,
            'interest_rate' => '20.0000',
            'interest_period' => 'per_loan',
            'repayment_frequency' => RepaymentFrequency::Daily,
            'processing_fee_type' => FeeType::None,
            'processing_fee_value' => '0.00',
            'insurance_fee_type' => FeeType::None,
            'insurance_fee_value' => '0.00',
            'late_payment_penalty_type' => FeeType::None,
            'late_payment_penalty_value' => '0.00',
            'grace_period_days' => 0,
            'requires_guarantor' => false,
            'minimum_guarantors' => 0,
            'requires_collateral' => false,
            'status' => 'active',
            'display_order' => 10,
        ];
    }

    public function weekly(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Weekly Repayment',
            'repayment_frequency' => RepaymentFrequency::Weekly,
            'tenor_unit' => TenorUnit::Weeks,
            'interest_rate' => '40.0000',
            'minimum_tenor' => 4,
            'maximum_tenor' => 52,
            'default_tenor' => 12,
        ]);
    }

    public function monthly(): self
    {
        return $this->state(fn (): array => [
            'name' => 'Monthly Repayment',
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'tenor_unit' => TenorUnit::Months,
            'interest_rate' => '40.0000',
            'minimum_tenor' => 1,
            'maximum_tenor' => 24,
            'default_tenor' => 6,
        ]);
    }

    public function retired(): self
    {
        return $this->state(fn (): array => ['status' => 'retired']);
    }

    public function withProcessingFee(string $percentage): self
    {
        return $this->state(fn (): array => [
            'processing_fee_type' => FeeType::Percentage,
            'processing_fee_value' => $percentage,
        ]);
    }
}
