<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Resources;

use App\Domains\LoanApplications\Models\LoanApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A loan application as seen by the merchant who applied for it.
 *
 * Distinct from LoanApplicationResource, which is what staff see. This one
 * drops internal credit-assessment commentary — `assessment.notes` and
 * `recommendation.notes` are staff working notes about the applicant, not
 * information for the applicant — and every internal staff attribution
 * (assessed/recommended/created by, branch).
 *
 * @mixin LoanApplication
 */
final class LoanApplicationSelfResource extends JsonResource
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

            'decision_reason' => $application->decision_reason,
            'withdrawal_reason' => $application->withdrawal_reason,

            'business' => $application->relationLoaded('business') && $application->business !== null ? [
                'id' => $application->business->id,
                'business_number' => $application->business->business_number,
                'business_name' => $application->business->business_name,
            ] : null,

            'loan_product' => $application->relationLoaded('loanProduct') && $application->loanProduct !== null ? [
                'id' => $application->loanProduct->id,
                'name' => $application->loanProduct->name,
                'requires_guarantor' => $application->loanProduct->requires_guarantor,
                'minimum_guarantors' => $application->loanProduct->minimum_guarantors,
            ] : null,

            'guarantors' => LoanApplicationGuarantorResource::collection($this->whenLoaded('guarantors')),
            'guarantors_satisfied' => $application->relationLoaded('loanProduct')
                ? $application->hasSufficientGuarantors()
                : null,

            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'expires_at' => $application->expires_at?->toIso8601String(),

            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }
}
