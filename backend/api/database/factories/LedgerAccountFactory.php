<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Ledger\Enums\AccountType;
use App\Domains\Ledger\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
final class LedgerAccountFactory extends Factory
{
    protected $model = LedgerAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(8000, 8999),
            'name' => fake()->words(3, true),
            'type' => AccountType::Asset,
            'is_system' => false,
            'status' => 'active',
        ];
    }

    public function liability(): self
    {
        return $this->state(fn (): array => ['type' => AccountType::Liability]);
    }

    public function income(): self
    {
        return $this->state(fn (): array => ['type' => AccountType::Income]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
