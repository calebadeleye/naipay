<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Enums;

/**
 * How interest is computed.
 *
 * Naipay's current products all use Flat. The other methods are implemented
 * because the brief requires them and because a product's method must be a
 * configuration choice, not a code change — but nothing in the book uses them
 * today, and each is covered by its own tests so the first product that does
 * is not the thing that discovers a bug.
 */
enum InterestMethod: string
{
    /**
     * Interest is charged once on the original principal for the whole term,
     * regardless of how much has been repaid. Standard for Nigerian
     * microfinance and what every Naipay product uses.
     */
    case Flat = 'flat';

    /** Interest accrues on the balance outstanding at each instalment. */
    case ReducingBalance = 'reducing_balance';

    /**
     * Principal is repaid in equal parts and interest is charged on the
     * declining balance, so instalments shrink over the term.
     */
    case DecliningBalance = 'declining_balance';

    /** Principal × rate × elapsed periods. */
    case SimpleInterest = 'simple_interest';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat rate',
            self::ReducingBalance => 'Reducing balance',
            self::DecliningBalance => 'Declining balance',
            self::SimpleInterest => 'Simple interest',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Flat => 'Interest is charged once on the amount borrowed and does not change with the term.',
            self::ReducingBalance => 'Interest is charged each period on the balance still outstanding.',
            self::DecliningBalance => 'Principal is repaid evenly and interest falls as the balance does, so instalments reduce.',
            self::SimpleInterest => 'Interest is charged per period on the original principal.',
        };
    }

    /**
     * Whether the term affects how much interest is charged.
     *
     * False for Flat, which is why a Naipay daily loan costs 20% whether it
     * runs for twenty working days or forty.
     */
    public function variesWithTerm(): bool
    {
        return $this !== self::Flat;
    }
}
