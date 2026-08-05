<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

/**
 * What a designated bank account is used for.
 *
 * Determines which flows are allowed to touch it: disbursement pulls from an
 * account purposed for Loan Disbursement, and the repayment instructions
 * shown to a merchant name the account purposed for Loan Repayment Collection.
 * A single account may hold more than one purpose in practice, but each loan
 * or repayment must be tied to one that is actually meant for that use.
 */
enum BankAccountPurpose: string
{
    case LoanRepaymentCollection = 'loan_repayment_collection';
    case LoanDisbursement = 'loan_disbursement';
    case OperatingAccount = 'operating_account';
    case SettlementAccount = 'settlement_account';
    case SuspenseAccount = 'suspense_account';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LoanRepaymentCollection => 'Loan Repayment Collection',
            self::LoanDisbursement => 'Loan Disbursement',
            self::OperatingAccount => 'Operating Account',
            self::SettlementAccount => 'Settlement Account',
            self::SuspenseAccount => 'Suspense Account',
            self::Other => 'Other',
        };
    }
}
