<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Resources;

use App\Domains\LoanApplications\Models\LoanApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanApplication
 */
final class LoanApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoanApplication $application */
        $application = $this->resource;

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,

            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'is_editable' => $application->status->isEditable(),
            'is_pending_decision' => $application->status->isPendingDecision(),
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $application->status->allowedTransitions(),
            ),

            'requested' => [
                'amount' => $application->requested_amount->jsonSerialize(),
                'tenor' => $application->requested_tenor,
            ],

            'approved' => $application->approved_at !== null ? [
                'amount' => $application->approved_amount?->jsonSerialize(),
                'tenor' => $application->approved_tenor,
                'interest_rate' => $application->approved_interest_rate,
            ] : null,

            'purpose' => $application->purpose,

            'assessment' => [
                'notes' => $application->assessment_notes,
                'assessed_at' => $application->assessed_at?->toIso8601String(),
                'assessed_by' => $this->staffSummary($application, 'assessedBy'),
            ],

            'recommendation' => [
                'notes' => $application->recommendation_notes,
                'recommended_at' => $application->recommended_at?->toIso8601String(),
                'recommended_by' => $this->staffSummary($application, 'recommendedBy'),
            ],

            'decision_reason' => $application->decision_reason,
            'withdrawal_reason' => $application->withdrawal_reason,

            'merchant' => $application->relationLoaded('merchant') && $application->merchant !== null ? [
                'id' => $application->merchant->id,
                'merchant_number' => $application->merchant->merchant_number,
                'full_name' => $application->merchant->fullName(),
                'can_borrow' => $application->merchant->canBorrow(),
            ] : null,

            'business' => $application->relationLoaded('business') && $application->business !== null ? [
                'id' => $application->business->id,
                'business_number' => $application->business->business_number,
                'business_name' => $application->business->business_name,
                'is_verified' => $application->business->isVerified(),
            ] : null,

            'loan_product' => $application->relationLoaded('loanProduct') && $application->loanProduct !== null ? [
                'id' => $application->loanProduct->id,
                'code' => $application->loanProduct->code,
                'name' => $application->loanProduct->name,
                'requires_guarantor' => $application->loanProduct->requires_guarantor,
                'minimum_guarantors' => $application->loanProduct->minimum_guarantors,
                // Collateral capture is not yet built; the flag is
                // informational only until that follow-up lands.
                'requires_collateral' => $application->loanProduct->requires_collateral,
            ] : null,

            'guarantors' => LoanApplicationGuarantorResource::collection($this->whenLoaded('guarantors')),
            'guarantors_satisfied' => $application->relationLoaded('loanProduct')
                ? $application->hasSufficientGuarantors()
                : null,

            'branch' => $application->relationLoaded('branch') && $application->branch !== null ? [
                'id' => $application->branch->id,
                'branch_code' => $application->branch->branch_code,
                'name' => $application->branch->name,
            ] : null,

            'created_by' => $this->staffSummary($application, 'createdBy'),

            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'expires_at' => $application->expires_at?->toIso8601String(),

            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function staffSummary(LoanApplication $application, string $relation): ?array
    {
        if (! $application->relationLoaded($relation) || $application->{$relation} === null) {
            return null;
        }

        $staff = $application->{$relation};

        return [
            'id' => $staff->id,
            'staff_number' => $staff->staff_number,
            'full_name' => $staff->fullName(),
        ];
    }
}
