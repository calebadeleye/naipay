<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Data;

use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Everything needed to compute a loan, and nothing else.
 *
 * Deliberately a plain value object with no database access: the calculator
 * that consumes it is pure, so a schedule can be previewed for an application
 * that does not exist yet, and every calculation is testable without a
 * database.
 */
final class LoanTerms
{
    public function __construct(
        public readonly Money $principal,
        public readonly InterestMethod $interestMethod,
        /** Percentage, as people state it: '20.0000' means 20%. */
        public readonly string $interestRate,
        public readonly RepaymentFrequency $frequency,
        /** Number of instalments, in the frequency's unit. */
        public readonly int $tenor,
        public readonly Carbon $disbursementDate,
        /**
         * Days before the first instalment falls due. Zero means the first
         * instalment is one period after disbursement.
         */
        public readonly int $gracePeriodDays = 0,
        public readonly ?Carbon $firstRepaymentDate = null,
    ) {
        if (! $principal->isPositive()) {
            throw new InvalidArgumentException('A loan principal must be greater than zero.');
        }

        if ($tenor < 1) {
            throw new InvalidArgumentException('A loan must have at least one instalment.');
        }

        if (preg_match('/^\d+(\.\d+)?$/', $interestRate) !== 1) {
            throw new InvalidArgumentException("Interest rate [{$interestRate}] is not a valid percentage.");
        }

        if ($gracePeriodDays < 0) {
            throw new InvalidArgumentException('A grace period cannot be negative.');
        }
    }
}
