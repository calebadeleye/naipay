<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Models\Loan;
use App\Domains\Repayments\Enums\PaymentMethod;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repayment>
 */
final class RepaymentFactory extends Factory
{
    protected $model = Repayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repayment_reference' => 'NPR-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),

            'loan_id' => Loan::factory()->disbursed(),

            'merchant_id' => function (array $attributes) {
                return Loan::query()->findOrFail($attributes['loan_id'])->merchant_id;
            },
            'business_id' => function (array $attributes) {
                return Loan::query()->findOrFail($attributes['loan_id'])->business_id;
            },

            'receiving_bank_account_id' => BankAccountFactory::new()->approved()->state([
                'account_purpose' => BankAccountPurpose::LoanRepaymentCollection,
            ]),

            'amount' => '7500.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer,
            'bank_reference' => fake()->unique()->bothify('TRX-########'),

            'status' => RepaymentStatus::Recorded,
            'recorded_by' => Staff::factory(),
        ];
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'status' => RepaymentStatus::Verified,
            'verified_by' => Staff::factory(),
            'verified_at' => now(),
        ]);
    }
}
