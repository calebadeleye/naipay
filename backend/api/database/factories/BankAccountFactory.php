<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Accounts\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
final class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_name' => fake()->randomElement(['Zenith Bank', 'Access Bank', 'GTBank', 'First Bank']),
            'account_name' => 'Naipay Microfinance',
            'account_number' => fake()->unique()->numerify('##########'),
            'currency' => 'NGN',
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
            'status' => BankAccountStatus::Active,
        ];
    }

    /**
     * Approved and therefore usable — the state most tests that need a real,
     * transactable account want, without walking the maker-checker flow.
     */
    public function approved(): self
    {
        return $this->state(fn (): array => [
            'approved_by' => \App\Domains\Identity\Models\Staff::factory(),
            'approved_at' => now(),
        ]);
    }

    public function forCollection(): self
    {
        return $this->state(fn (): array => ['account_purpose' => BankAccountPurpose::LoanRepaymentCollection]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => BankAccountStatus::Suspended]);
    }
}
