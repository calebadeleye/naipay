<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Resources;

use App\Domains\Repayments\Models\Repayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Repayment
 */
final class RepaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Repayment $repayment */
        $repayment = $this->resource;

        return [
            'id' => $repayment->id,
            'repayment_reference' => $repayment->repayment_reference,

            'status' => $repayment->status->value,
            'status_label' => $repayment->status->label(),
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $repayment->status->allowedTransitions(),
            ),

            'amount' => $repayment->amount->jsonSerialize(),
            'payment_date' => $repayment->payment_date->toDateString(),
            'payment_method' => $repayment->payment_method->value,
            'payment_method_label' => $repayment->payment_method->label(),

            'sender_account_name' => $repayment->sender_account_name,
            'sender_account_number' => $repayment->sender_account_number,
            'sender_bank_name' => $repayment->sender_bank_name,
            'bank_reference' => $repayment->bank_reference,
            'notes' => $repayment->notes,

            'allocation' => $repayment->allocated_principal !== null ? [
                'principal' => $repayment->allocated_principal->jsonSerialize(),
                'interest' => $repayment->allocated_interest?->jsonSerialize(),
                'fee' => $repayment->allocated_fee?->jsonSerialize(),
                'excess' => $repayment->allocated_excess?->jsonSerialize(),
                'unallocated' => $repayment->allocated_unallocated?->jsonSerialize(),
                'entries' => RepaymentAllocationResource::collection($this->whenLoaded('allocations')),
            ] : null,

            'loan' => $repayment->relationLoaded('loan') && $repayment->loan !== null ? [
                'id' => $repayment->loan->id,
                'loan_reference' => $repayment->loan->loan_reference,
                'status' => $repayment->loan->status->value,
            ] : null,

            'merchant' => $repayment->relationLoaded('merchant') && $repayment->merchant !== null ? [
                'id' => $repayment->merchant->id,
                'merchant_number' => $repayment->merchant->merchant_number,
                'full_name' => $repayment->merchant->fullName(),
                'account_number' => $repayment->merchant->relationLoaded('account') && $repayment->merchant->account !== null
                    ? $repayment->merchant->account->account_number
                    : null,
                'account_number_formatted' => $repayment->merchant->relationLoaded('account') && $repayment->merchant->account !== null
                    ? $repayment->merchant->account->formattedAccountNumber()
                    : null,
            ] : null,

            'receiving_bank_account' => $repayment->relationLoaded('receivingBankAccount') && $repayment->receivingBankAccount !== null
                ? $repayment->receivingBankAccount->label()
                : null,

            'verification' => $repayment->verified_at !== null ? [
                'notes' => $repayment->verification_notes,
                'verified_by' => $this->staffSummary($repayment, 'verifiedBy'),
                'verified_at' => $repayment->verified_at->toIso8601String(),
            ] : null,

            'rejection' => $repayment->rejected_at !== null ? [
                'reason' => $repayment->rejection_reason,
                'rejected_by' => $this->staffSummary($repayment, 'rejectedBy'),
                'rejected_at' => $repayment->rejected_at->toIso8601String(),
            ] : null,

            'reversal' => $repayment->reversed_at !== null ? [
                'reason' => $repayment->reversal_reason,
                'reversed_by' => $this->staffSummary($repayment, 'reversedBy'),
                'reversed_at' => $repayment->reversed_at->toIso8601String(),
            ] : null,

            'recorded_by' => $this->staffSummary($repayment, 'recordedBy'),
            'approved_by' => $this->staffSummary($repayment, 'approvedBy'),

            'created_at' => $repayment->created_at?->toIso8601String(),
            'updated_at' => $repayment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffSummary(Repayment $repayment, string $relation): ?array
    {
        if (! $repayment->relationLoaded($relation) || $repayment->{$relation} === null) {
            return null;
        }

        $staff = $repayment->{$relation};

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->fullName(),
        ];
    }
}
