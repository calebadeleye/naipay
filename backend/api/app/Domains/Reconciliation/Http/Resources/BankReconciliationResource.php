<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Resources;

use App\Domains\Reconciliation\Models\BankReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankReconciliation
 */
final class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankReconciliation $reconciliation */
        $reconciliation = $this->resource;

        return [
            'id' => $reconciliation->id,

            'status' => $reconciliation->status->value,
            'status_label' => $reconciliation->status->label(),
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $reconciliation->status->allowedTransitions(),
            ),

            'bank_account' => $reconciliation->relationLoaded('bankAccount') && $reconciliation->bankAccount !== null
                ? $reconciliation->bankAccount->label()
                : null,

            'period_start' => $reconciliation->period_start->toDateString(),
            'period_end' => $reconciliation->period_end->toDateString(),
            'statement_opening_balance' => $reconciliation->statement_opening_balance->jsonSerialize(),
            'statement_closing_balance' => $reconciliation->statement_closing_balance->jsonSerialize(),

            'notes' => $reconciliation->notes,

            'lines' => BankStatementLineResource::collection($this->whenLoaded('lines')),
            'has_unresolved_lines' => $reconciliation->relationLoaded('lines')
                ? $reconciliation->lines->contains(fn ($line): bool => ! $line->isMatched() && $line->excluded_reason === null)
                : null,

            'prepared_by' => $this->staffSummary($reconciliation, 'preparedBy'),
            'submitted_at' => $reconciliation->submitted_at?->toIso8601String(),
            'approved_by' => $this->staffSummary($reconciliation, 'approvedBy'),
            'approved_at' => $reconciliation->approved_at?->toIso8601String(),

            'created_at' => $reconciliation->created_at?->toIso8601String(),
            'updated_at' => $reconciliation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffSummary(BankReconciliation $reconciliation, string $relation): ?array
    {
        if (! $reconciliation->relationLoaded($relation) || $reconciliation->{$relation} === null) {
            return null;
        }

        $staff = $reconciliation->{$relation};

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->fullName(),
        ];
    }
}
