<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Enums;

/**
 * The five classical account types.
 *
 * What actually distinguishes them is which side a normal, healthy balance
 * sits on — an asset grows with a debit, a liability grows with a credit.
 * Every posting rule in the ledger ultimately traces back to this.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Income => 'Income',
            self::Expense => 'Expense',
        };
    }

    /**
     * The side a debit posting increases this account type's balance on.
     * Assets and expenses are debit-normal; liabilities, equity and income
     * are credit-normal.
     */
    public function normalBalance(): DebitCredit
    {
        return match ($this) {
            self::Asset, self::Expense => DebitCredit::Debit,
            self::Liability, self::Equity, self::Income => DebitCredit::Credit,
        };
    }
}
