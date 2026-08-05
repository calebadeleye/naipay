<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Data;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * One row of a repayment schedule.
 */
final class Instalment
{
    public function __construct(
        public readonly int $number,
        public readonly Carbon $dueDate,
        /** Principal outstanding before this instalment is paid. */
        public readonly Money $openingPrincipal,
        public readonly Money $principalDue,
        public readonly Money $interestDue,
        public readonly Money $feeDue,
    ) {}

    public function totalDue(): Money
    {
        return $this->principalDue->plus($this->interestDue)->plus($this->feeDue);
    }

    public function closingPrincipal(): Money
    {
        return $this->openingPrincipal->minus($this->principalDue);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'installment_number' => $this->number,
            'due_date' => $this->dueDate->toDateString(),
            'opening_principal' => $this->openingPrincipal->toDecimalString(),
            'principal_due' => $this->principalDue->toDecimalString(),
            'interest_due' => $this->interestDue->toDecimalString(),
            'fee_due' => $this->feeDue->toDecimalString(),
            'total_due' => $this->totalDue()->toDecimalString(),
            'closing_principal' => $this->closingPrincipal()->toDecimalString(),
        ];
    }
}
