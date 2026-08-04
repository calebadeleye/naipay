<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountStatus;
use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Sequences\AccountNumberGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Opens and maintains merchant accounts.
 */
final class MerchantAccountService
{
    private const MODULE = 'accounts';

    public function __construct(
        private readonly AccountNumberGenerator $accountNumbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Opens a merchant's account, the final step of onboarding.
     *
     * Idempotent: an approval that is somehow replayed must not leave a
     * merchant holding two account numbers.
     */
    public function openFor(Merchant $merchant, ?Staff $actor = null): MerchantAccount
    {
        $existing = MerchantAccount::query()->where('merchant_id', $merchant->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($merchant, $actor): MerchantAccount {
            $account = new MerchantAccount([
                'merchant_id' => $merchant->getKey(),
                'account_name' => $merchant->fullName(),
                'currency' => (string) config('naipay.currency', 'NGN'),
            ]);

            $account->forceFill([
                'account_number' => $this->accountNumbers->next(),
                'status' => AccountStatus::Active,
                'opened_by' => $actor?->getKey(),
                'opened_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'account.opened',
                module: self::MODULE,
                subject: $account,
                newValues: [
                    'merchant_id' => $merchant->getKey(),
                    'account_number' => $account->account_number,
                ],
                eventType: 'create',
                actor: $actor,
            );

            return $account->fresh();
        });
    }
}
