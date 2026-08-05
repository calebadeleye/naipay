<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Data;

use App\Support\Exceptions\FinancialIntegrityException;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * A complete repayment schedule.
 *
 * Self-checking: the constructor refuses a schedule whose instalments do not
 * sum back to the loan exactly. A schedule that is a kobo out will not close,
 * and the merchant either cannot settle or is left owing nothing while the
 * balance says otherwise — so it is caught here rather than discovered on the
 * final payment months later.
 */
final class LoanSchedule
{
    /**
     * @param  array<int, Instalment>  $instalments
     */
    public function __construct(
        public readonly array $instalments,
        public readonly Money $principal,
        public readonly Money $totalInterest,
        public readonly Money $totalFees,
    ) {
        $this->assertInstalmentsReconcile();
    }

    public function totalPayable(): Money
    {
        return $this->principal->plus($this->totalInterest)->plus($this->totalFees);
    }

    public function instalmentCount(): int
    {
        return count($this->instalments);
    }

    public function firstRepaymentDate(): Carbon
    {
        return $this->instalments[0]->dueDate;
    }

    public function maturityDate(): Carbon
    {
        return $this->instalments[count($this->instalments) - 1]->dueDate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (Instalment $instalment): array => $instalment->toArray(),
            $this->instalments,
        );
    }

    /**
     * Every instalment must sum back to the loan, to the kobo.
     */
    private function assertInstalmentsReconcile(): void
    {
        if ($this->instalments === []) {
            throw new FinancialIntegrityException('A repayment schedule must contain at least one instalment.');
        }

        $principal = Money::sum(array_map(
            static fn (Instalment $i): Money => $i->principalDue,
            $this->instalments,
        ));

        $interest = Money::sum(array_map(
            static fn (Instalment $i): Money => $i->interestDue,
            $this->instalments,
        ));

        $fees = Money::sum(array_map(
            static fn (Instalment $i): Money => $i->feeDue,
            $this->instalments,
        ));

        if (! $principal->equals($this->principal)) {
            throw new FinancialIntegrityException(
                'Scheduled principal does not equal the loan principal.',
                [
                    'scheduled' => $principal->toDecimalString(),
                    'expected' => $this->principal->toDecimalString(),
                ],
            );
        }

        if (! $interest->equals($this->totalInterest)) {
            throw new FinancialIntegrityException(
                'Scheduled interest does not equal the total interest.',
                [
                    'scheduled' => $interest->toDecimalString(),
                    'expected' => $this->totalInterest->toDecimalString(),
                ],
            );
        }

        if (! $fees->equals($this->totalFees)) {
            throw new FinancialIntegrityException(
                'Scheduled fees do not equal the total fees.',
                [
                    'scheduled' => $fees->toDecimalString(),
                    'expected' => $this->totalFees->toDecimalString(),
                ],
            );
        }
    }
}
