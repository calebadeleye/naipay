<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Support;

/**
 * Codes for the accounts every Naipay domain service posts against.
 *
 * Named constants rather than magic strings scattered through the codebase —
 * a call site writes `StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE`, and the
 * one place that could ever need to change is here. `ChartOfAccountsSeeder`
 * is what actually creates the rows these codes point to.
 */
final class StandardAccounts
{
    public const CASH_AT_BANK = '1000';

    public const LOAN_PRINCIPAL_RECEIVABLE = '1100';

    public const INTEREST_RECEIVABLE = '1110';

    public const PENALTY_RECEIVABLE = '1120';

    public const PROCESSING_FEE_RECEIVABLE = '1130';

    public const SUSPENSE_ACCOUNT = '1900';

    /** A contra-asset: carried as a credit balance offsetting gross receivables. */
    public const LOAN_LOSS_PROVISION = '1950';

    public const MERCHANT_SAVINGS_LIABILITY = '2100';

    public const SHARE_CAPITAL = '3000';

    public const INTEREST_INCOME = '4100';

    public const PENALTY_INCOME = '4110';

    public const PROCESSING_FEE_INCOME = '4120';

    public const OPERATING_EXPENSES = '5000';

    /** Principal accepted as lost. Written off from the receivable above. */
    public const WRITTEN_OFF_LOANS = '5100';

    private function __construct() {}
}
