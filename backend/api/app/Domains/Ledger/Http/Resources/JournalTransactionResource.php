<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Http\Resources;

use App\Domains\Ledger\Models\JournalTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalTransaction
 */
final class JournalTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalTransaction $transaction */
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'transaction_reference' => $transaction->transaction_reference,
            'transaction_type' => $transaction->transaction_type,
            'description' => $transaction->description,
            'currency' => $transaction->currency,

            'source_type' => $transaction->source_type !== null ? class_basename($transaction->source_type) : null,
            'source_id' => $transaction->source_id,

            'transaction_date' => $transaction->transaction_date->toDateString(),
            'posting_date' => $transaction->posting_date->toDateString(),

            'status' => $transaction->status->value,
            'status_label' => $transaction->status->label(),
            'is_reversal' => $transaction->isReversal(),
            'reversal_of_id' => $transaction->reversal_of_id,
            'reversed_by_id' => $transaction->reversed_by_id,
            'reversed_at' => $transaction->reversed_at?->toIso8601String(),

            'created_by' => $transaction->relationLoaded('createdBy') && $transaction->createdBy !== null
                ? $transaction->createdBy->fullName()
                : null,

            'entries' => $transaction->relationLoaded('entries')
                ? $transaction->entries->map(fn ($entry): array => [
                    'account_code' => $entry->account->code,
                    'account_name' => $entry->account->name,
                    'debit_amount' => $entry->debit_amount->jsonSerialize(),
                    'credit_amount' => $entry->credit_amount->jsonSerialize(),
                    'description' => $entry->description,
                    'loan_id' => $entry->loan_id,
                    'merchant_id' => $entry->merchant_id,
                ])->all()
                : null,

            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }
}
