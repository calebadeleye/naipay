<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Accounts\Enums\AccountStatus;
use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantAccount>
 */
final class MerchantAccountFactory extends Factory
{
    protected $model = MerchantAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory()->approved(),
            'account_number' => fake()->unique()->numerify('##########'),
            'account_name' => 'Test Merchant',
            'account_type' => 'merchant_wallet',
            'currency' => 'NGN',
            'status' => AccountStatus::Active,
            'available_balance' => '0.00',
            'ledger_balance' => '0.00',
            'opened_at' => now(),
        ];
    }
}
