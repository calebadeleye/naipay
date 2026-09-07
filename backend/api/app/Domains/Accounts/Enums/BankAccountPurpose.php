<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

/**
 * What a designated bank account is used for.
 *
 * Determines which flows are allowed to touch it: disbursement pulls from an
 * account purposed for Loan Disbursement, and the repayment instructions
 * shown to a merchant name the account purposed for Loan Repayment Collection.
 * A single account may hold more than one purpose at once — most commonly it
 * is both — and each is stored on `bank_accounts.purposes`. A loan or
 * repayment must still be tied to an account that actually carries the
 * purpose for that use.
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

    /**
     * @param  iterable<self>  $purposes
     * @return list<string>
     */
    public static function labelsFor(iterable $purposes): array
    {
        $labels = [];

        foreach ($purposes as $purpose) {
            $labels[] = $purpose->label();
        }

        return $labels;
    }
}
