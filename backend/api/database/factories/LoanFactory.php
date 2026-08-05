<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
final class LoanFactory extends Factory
{
    protected $model = Loan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_reference' => 'NPL-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),

            'loan_application_id' => LoanApplication::factory()->approved(),

            'merchant_id' => function (array $attributes) {
                return LoanApplication::query()->findOrFail($attributes['loan_application_id'])->merchant_id;
            },
            'business_id' => function (array $attributes) {
                return LoanApplication::query()->findOrFail($attributes['loan_application_id'])->business_id;
            },
            'loan_product_id' => function (array $attributes) {
                return LoanApplication::query()->findOrFail($attributes['loan_application_id'])->loan_product_id;
            },

            'principal_amount' => '150000.00',
            'interest_method' => InterestMethod::Flat,
            'interest_rate' => '20.0000',
            'repayment_frequency' => RepaymentFrequency::Daily,
            'tenor' => 20,
            'grace_period_days' => 0,

            'status' => LoanStatus::PendingApproval,
            'created_by' => Staff::factory(),
        ];
    }

    public function pendingDisbursement(): self
    {
        return $this->state(fn (): array => [
            'status' => LoanStatus::PendingDisbursement,
            'approved_by' => Staff::factory(),
            'approved_at' => now(),
        ]);
    }

    public function disbursed(): self
    {
        return $this->pendingDisbursement()->state(fn (array $attributes): array => [
            'status' => LoanStatus::Disbursed,
            'total_interest' => '30000.00',
            'total_fees' => '0.00',
            'total_payable' => '180000.00',
            'outstanding_principal' => $attributes['principal_amount'] ?? '150000.00',
            'outstanding_interest' => '30000.00',
            'outstanding_fees' => '0.00',
            'disbursement_bank_account_id' => BankAccountFactory::new()->approved(),
            'disbursement_date' => now()->toDateString(),
            'first_repayment_date' => now()->addDay()->toDateString(),
            'maturity_date' => now()->addDays(30)->toDateString(),
            'disbursed_by' => Staff::factory(),
            'disbursed_at' => now(),
        ]);
    }

    public function writtenOff(): self
    {
        return $this->disbursed()->state(fn (): array => [
            'status' => LoanStatus::WrittenOff,
            'outstanding_principal' => '0.00',
            'written_off_by' => Staff::factory(),
            'written_off_at' => now(),
            'write_off_reason' => 'Merchant ceased trading; principal deemed unrecoverable.',
        ]);
    }

    public function forProduct(LoanProduct $product): self
    {
        return $this->state(fn (): array => [
            'loan_product_id' => $product->id,
            'interest_method' => $product->interest_method,
            'interest_rate' => $product->interest_rate,
            'repayment_frequency' => $product->repayment_frequency,
        ]);
    }
}
