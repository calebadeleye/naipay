<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Models\Staff;
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
            'purposes' => [BankAccountPurpose::LoanDisbursement->value],
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
            'approved_by' => Staff::factory(),
            'approved_at' => now(),
        ]);
    }

    public function forCollection(): self
    {
        return $this->state(fn (): array => [
            'purposes' => [BankAccountPurpose::LoanRepaymentCollection->value],
        ]);
    }

    /**
     * An account that carries every given purpose at once — the common case
     * of one account used for both disbursement and repayment collection.
     */
    public function forPurposes(BankAccountPurpose ...$purposes): self
    {
        return $this->state(fn (): array => [
            'purposes' => array_map(fn (BankAccountPurpose $purpose): string => $purpose->value, $purposes),
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => BankAccountStatus::Suspended]);
    }
}
