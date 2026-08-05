<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Resources;

use App\Domains\Loans\Models\Loan;
use App\Domains\Reconciliation\Models\BankStatementLine;
use App\Domains\Repayments\Models\Repayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankStatementLine
 */
final class BankStatementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankStatementLine $line */
        $line = $this->resource;

        return [
            'id' => $line->id,
            'statement_date' => $line->statement_date->toDateString(),
            'description' => $line->description,
            'external_reference' => $line->external_reference,
            'amount' => $line->amount->jsonSerialize(),
            'direction' => $line->direction->value,
            'direction_label' => $line->direction->label(),

            'status' => $line->status->value,
            'status_label' => $line->status->label(),

            'matched_to' => $this->matchedToSummary($line),
            'matched_at' => $line->matched_at?->toIso8601String(),

            'excluded_reason' => $line->excluded_reason,

            'created_at' => $line->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function matchedToSummary(BankStatementLine $line): ?array
    {
        if (! $line->isMatched()) {
            return null;
        }

        $record = $line->matchedRecord();

        return match (true) {
            $record instanceof Repayment => [
                'type' => 'repayment',
                'id' => $record->id,
                'reference' => $record->repayment_reference,
            ],
            $record instanceof Loan => [
                'type' => 'loan',
                'id' => $record->id,
                'reference' => $record->loan_reference,
            ],
            default => null,
        };
    }
}
