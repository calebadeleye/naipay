<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Resources;

use App\Domains\Loans\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Loan
 */
final class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Loan $loan */
        $loan = $this->resource;

        return [
            'id' => $loan->id,
            'loan_reference' => $loan->loan_reference,

            'status' => $loan->status->value,
            'status_label' => $loan->status->label(),
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $loan->status->allowedTransitions(),
            ),

            'terms' => [
                'principal_amount' => $loan->principal_amount->jsonSerialize(),
                'interest_method' => $loan->interest_method->value,
                'interest_rate' => $loan->interest_rate,
                'repayment_frequency' => $loan->repayment_frequency->value,
                'tenor' => $loan->tenor,
                'grace_period_days' => $loan->grace_period_days,
            ],

            'totals' => $loan->total_payable !== null ? [
                'total_interest' => $loan->total_interest?->jsonSerialize(),
                'total_fees' => $loan->total_fees?->jsonSerialize(),
                'total_payable' => $loan->total_payable->jsonSerialize(),
            ] : null,

            'outstanding' => $loan->outstanding_principal !== null ? [
                'principal' => $loan->outstanding_principal->jsonSerialize(),
                'interest' => $loan->outstanding_interest?->jsonSerialize(),
                'fees' => $loan->outstanding_fees?->jsonSerialize(),
            ] : null,

            'disbursement' => $loan->disbursed_at !== null ? [
                'bank_account' => $loan->relationLoaded('disbursementBankAccount') && $loan->disbursementBankAccount !== null
                    ? $loan->disbursementBankAccount->label()
                    : null,
                'date' => $loan->disbursement_date?->toDateString(),
                'first_repayment_date' => $loan->first_repayment_date?->toDateString(),
                'maturity_date' => $loan->maturity_date?->toDateString(),
                'disbursed_by' => $this->staffSummary($loan, 'disbursedBy'),
                'disbursed_at' => $loan->disbursed_at->toIso8601String(),
            ] : null,

            'write_off' => $loan->written_off_at !== null ? [
                'reason' => $loan->write_off_reason,
                'written_off_by' => $this->staffSummary($loan, 'writtenOffBy'),
                'written_off_at' => $loan->written_off_at->toIso8601String(),
            ] : null,

            'merchant' => $loan->relationLoaded('merchant') && $loan->merchant !== null ? [
                'id' => $loan->merchant->id,
                'merchant_number' => $loan->merchant->merchant_number,
                'full_name' => $loan->merchant->fullName(),
                'email' => $loan->merchant->email,
            ] : null,

            'business' => $loan->relationLoaded('business') && $loan->business !== null ? [
                'id' => $loan->business->id,
                'business_number' => $loan->business->business_number,
                'business_name' => $loan->business->business_name,
            ] : null,

            'loan_product' => $loan->relationLoaded('loanProduct') && $loan->loanProduct !== null ? [
                'id' => $loan->loanProduct->id,
                'code' => $loan->loanProduct->code,
                'name' => $loan->loanProduct->name,
            ] : null,

            'loan_application_id' => $loan->loan_application_id,

            'schedule' => LoanScheduleEntryResource::collection($this->whenLoaded('scheduleEntries')),

            'created_by' => $this->staffSummary($loan, 'createdBy'),
            'approved_by' => $this->staffSummary($loan, 'approvedBy'),

            'created_at' => $loan->created_at?->toIso8601String(),
            'updated_at' => $loan->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffSummary(Loan $loan, string $relation): ?array
    {
        if (! $loan->relationLoaded($relation) || $loan->{$relation} === null) {
            return null;
        }

        $staff = $loan->{$relation};

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->fullName(),
        ];
    }
}
