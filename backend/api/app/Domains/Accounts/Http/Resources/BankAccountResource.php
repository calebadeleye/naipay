<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Http\Resources;

use App\Domains\Accounts\Models\BankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankAccount
 */
final class BankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'bank_code' => $account->bank_code,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'account_number_formatted' => $account->formattedAccountNumber(),
            'branch_name' => $account->branch_name,
            'currency' => $account->currency,

            'purposes' => $account->purposeValues(),
            'purpose_labels' => $account->purposeLabels(),

            'is_default_collection_account' => $account->is_default_collection_account,
            'is_default_disbursement_account' => $account->is_default_disbursement_account,

            'status' => $account->status->value,
            'status_label' => $account->status->label(),
            'is_approved' => $account->isApproved(),
            'can_transact' => $account->canTransact(),

            'approved_by' => $account->relationLoaded('approvedBy') && $account->approvedBy !== null
                ? $account->approvedBy->fullName()
                : null,
            'approved_at' => $account->approved_at?->toIso8601String(),

            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
