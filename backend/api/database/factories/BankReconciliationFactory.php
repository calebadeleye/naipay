<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Identity\Models\Staff;
use App\Domains\Reconciliation\Enums\BankReconciliationStatus;
use App\Domains\Reconciliation\Models\BankReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliation>
 */
final class BankReconciliationFactory extends Factory
{
    protected $model = BankReconciliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccountFactory::new()->approved()->state([
                'account_purpose' => BankAccountPurpose::LoanRepaymentCollection,
            ]),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'statement_opening_balance' => '0.00',
            'statement_closing_balance' => '0.00',
            'status' => BankReconciliationStatus::InProgress,
            'prepared_by' => Staff::factory(),
        ];
    }

    public function pendingApproval(): self
    {
        return $this->state(fn (): array => [
            'status' => BankReconciliationStatus::PendingApproval,
            'submitted_at' => now(),
        ]);
    }
}
