<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Identity\Models\Staff;
use App\Domains\Reconciliation\Enums\BankStatementLineDirection;
use App\Domains\Reconciliation\Enums\BankStatementLineStatus;
use App\Domains\Reconciliation\Models\BankReconciliation;
use App\Domains\Reconciliation\Models\BankStatementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankStatementLine>
 */
final class BankStatementLineFactory extends Factory
{
    protected $model = BankStatementLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_reconciliation_id' => BankReconciliation::factory(),
            'bank_account_id' => function (array $attributes) {
                return BankReconciliation::query()->findOrFail($attributes['bank_reconciliation_id'])->bank_account_id;
            },
            'statement_date' => now()->toDateString(),
            'description' => 'Test transaction',
            'amount' => '1000.00',
            'direction' => BankStatementLineDirection::Credit,
            'status' => BankStatementLineStatus::Unmatched,
            'created_by' => Staff::factory(),
        ];
    }

    public function excluded(): self
    {
        return $this->state(fn (): array => [
            'status' => BankStatementLineStatus::Excluded,
            'excluded_reason' => 'Bank charge not tied to any Naipay transaction.',
        ]);
    }
}
