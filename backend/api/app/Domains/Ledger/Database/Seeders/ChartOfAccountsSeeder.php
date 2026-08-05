<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Database\Seeders;

use App\Domains\Ledger\Enums\AccountType;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Support\StandardAccounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The standard chart of accounts every Naipay domain service posts against.
 *
 * Structural, not business-configurable data — unlike a loan product's
 * pricing, an administrator does not get to rename what "Cash at Bank" means
 * without breaking every posting that already references its code. Synced on
 * every deploy: name and type always match what is defined here, and only
 * activation status is left for an administrator to manage.
 */
final class ChartOfAccountsSeeder extends Seeder
{
    /**
     * code => [name, type].
     *
     * @var array<string, array{0: string, 1: AccountType}>
     */
    private const ACCOUNTS = [
        StandardAccounts::CASH_AT_BANK => ['Cash at Bank', AccountType::Asset],
        StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE => ['Loan Principal Receivable', AccountType::Asset],
        StandardAccounts::INTEREST_RECEIVABLE => ['Interest Receivable', AccountType::Asset],
        StandardAccounts::PENALTY_RECEIVABLE => ['Penalty Receivable', AccountType::Asset],
        StandardAccounts::PROCESSING_FEE_RECEIVABLE => ['Processing Fee Receivable', AccountType::Asset],
        StandardAccounts::SUSPENSE_ACCOUNT => ['Suspense Account', AccountType::Asset],
        StandardAccounts::LOAN_LOSS_PROVISION => ['Loan Loss Provision', AccountType::Asset],
        StandardAccounts::MERCHANT_SAVINGS_LIABILITY => ['Merchant Savings Liability', AccountType::Liability],
        StandardAccounts::SHARE_CAPITAL => ['Share Capital', AccountType::Equity],
        StandardAccounts::INTEREST_INCOME => ['Interest Income', AccountType::Income],
        StandardAccounts::PENALTY_INCOME => ['Penalty Income', AccountType::Income],
        StandardAccounts::PROCESSING_FEE_INCOME => ['Processing Fee Income', AccountType::Income],
        StandardAccounts::OPERATING_EXPENSES => ['Operating Expenses', AccountType::Expense],
        StandardAccounts::WRITTEN_OFF_LOANS => ['Written-Off Loans', AccountType::Expense],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::ACCOUNTS as $code => [$name, $type]) {
                $account = LedgerAccount::query()->firstOrNew(['code' => $code]);

                $account->name = $name;
                $account->type = $type;
                $account->is_system = true;

                if (! $account->exists) {
                    $account->status = 'active';
                }

                $account->save();
            }
        });
    }
}
