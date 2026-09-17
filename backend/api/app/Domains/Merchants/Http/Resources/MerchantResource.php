<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Resources;

use App\Domains\Businesses\Http\Resources\BusinessResource;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant as returned by the API.
 *
 * Identity numbers are masked here, on the way out, rather than in the browser.
 * Sending the full value and hiding it client-side would leave it in the
 * response body, the browser cache, and any proxy in between — the mask would
 * be decoration, not a control.
 *
 * The unmasked value is included only for callers holding
 * merchants.view_sensitive, and never in a list response.
 *
 * @mixin Merchant
 */
final class MerchantResource extends JsonResource
{
    public function __construct(
        Merchant $resource,
        /**
         * List responses never carry unmasked identity numbers, whatever the
         * caller holds: a single screen of results would otherwise put fifty
         * BVNs into one payload.
         */
        private readonly bool $detailed = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->resource;

        $canSeeSensitive = $this->detailed
            && ($request->user()?->can(Permission::MerchantsViewSensitive->value) ?? false);

        return [
            'id' => $merchant->id,
            'merchant_number' => $merchant->merchant_number,

            'first_name' => $merchant->first_name,
            'middle_name' => $merchant->middle_name,
            'last_name' => $merchant->last_name,
            'full_name' => $merchant->fullName(),

            'date_of_birth' => $merchant->date_of_birth?->toDateString(),
            'gender' => $merchant->gender,
            'marital_status' => $merchant->marital_status,
            'employment_status' => $merchant->employment_status,
            'preferred_language' => $merchant->preferred_language,

            'phone' => $merchant->phone,
            'alternative_phone' => $merchant->alternative_phone,
            'email' => $merchant->email,

            'residential_address' => $merchant->residential_address,
            'city' => $merchant->city,
            'state' => $merchant->state,
            'country' => $merchant->country,

            'identity' => [
                'bvn_masked' => $merchant->maskedBvn(),
                'nin_masked' => $merchant->maskedNin(),
                'has_bvn' => $merchant->bvn !== null,
                'has_nin' => $merchant->nin !== null,

                // Present only on a detail view, and only with the permission.
                // The client is told whether it is seeing the full value so it
                // can label the field accordingly.
                'bvn' => $canSeeSensitive ? $merchant->bvn : null,
                'nin' => $canSeeSensitive ? $merchant->nin : null,
                'unmasked' => $canSeeSensitive,
            ],

            'profile_photo' => $merchant->profile_photo,

            'onboarding_status' => $merchant->onboarding_status->value,
            'onboarding_status_label' => $merchant->onboarding_status->label(),
            'is_editable' => $merchant->onboarding_status->isEditable(),
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $merchant->onboarding_status->allowedTransitions(),
            ),

            'merchant_status' => $merchant->merchant_status->value,
            'merchant_status_label' => $merchant->merchant_status->label(),

            'kyc_status' => $merchant->kyc_status->value,
            'kyc_status_label' => $merchant->kyc_status->label(),

            'risk_rating' => $merchant->risk_rating?->value,
            'risk_rating_label' => $merchant->risk_rating?->label(),

            'can_borrow' => $merchant->canBorrow(),

            // Answers "has this merchant finished paying off their loan(s)?"
            // without the caller having to fetch and reduce the loans list
            // itself. Taking another loan is never gated on this — a
            // merchant with every loan fully paid, or none at all, is just
            // as eligible as one mid-repayment; see Merchant::canBorrow().
            'loan_summary' => $merchant->relationLoaded('loans')
                ? $this->loanSummary($merchant->loans)
                : null,

            'rejection_reason' => $merchant->rejection_reason,
            'suspension_reason' => $merchant->suspension_reason,

            'branch' => $merchant->relationLoaded('branch') && $merchant->branch !== null ? [
                'id' => $merchant->branch->id,
                'branch_code' => $merchant->branch->branch_code,
                'name' => $merchant->branch->name,
            ] : null,

            'assigned_officer' => $merchant->relationLoaded('assignedOfficer') && $merchant->assignedOfficer !== null ? [
                'id' => $merchant->assignedOfficer->id,
                'staff_number' => $merchant->assignedOfficer->staff_number,
                'full_name' => $merchant->assignedOfficer->fullName(),
            ] : null,

            // Opened at approval, so a merchant in draft legitimately has none.
            'account' => $merchant->relationLoaded('account') && $merchant->account !== null ? [
                'id' => $merchant->account->id,
                'account_number' => $merchant->account->account_number,
                'account_number_formatted' => $merchant->account->formattedAccountNumber(),
                'account_name' => $merchant->account->account_name,
                'currency' => $merchant->account->currency,
                'status' => $merchant->account->status->value,
                'available_balance' => $merchant->account->available_balance?->jsonSerialize(),
            ] : null,

            'businesses' => BusinessResource::collection($this->whenLoaded('businesses')),
            'businesses_count' => $this->whenCounted('businesses'),

            'submitted_at' => $merchant->submitted_at?->toIso8601String(),
            'verified_at' => $merchant->verified_at?->toIso8601String(),
            'approved_at' => $merchant->approved_at?->toIso8601String(),

            'created_at' => $merchant->created_at?->toIso8601String(),
            'updated_at' => $merchant->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Rolls the merchant's disbursed loans up into one "where do they stand"
     * summary: total paid (principal + interest + fees, across every
     * disbursed loan), what's still outstanding, and whether every loan is
     * fully paid off. Loans never disbursed (pending approval/disbursement)
     * carry no balances yet and are excluded.
     *
     * @param  Collection<int, Loan>  $loans
     * @return array<string, mixed>
     */
    private function loanSummary(Collection $loans): array
    {
        $disbursed = $loans->filter(fn (Loan $loan): bool => $loan->status === LoanStatus::Disbursed);

        $totalPaid = null;
        $totalOutstanding = null;
        $fullyPaidCount = 0;

        foreach ($disbursed as $loan) {
            $paid = $loan->totalPaid();
            $outstanding = $loan->outstanding_principal
                ->plus($loan->outstanding_interest)
                ->plus($loan->outstanding_fees);

            $totalPaid = $totalPaid === null ? $paid : $totalPaid->plus($paid);
            $totalOutstanding = $totalOutstanding === null ? $outstanding : $totalOutstanding->plus($outstanding);

            if ($loan->isFullyPaid()) {
                $fullyPaidCount++;
            }
        }

        // The rate(s) the merchant is actually borrowing at. `interest_rate`
        // is only set when every disbursed loan shares one rate — with
        // several loans at different rates there's no single figure to show,
        // so the caller falls back to the full `interest_rates` list.
        $interestRates = $disbursed
            ->pluck('interest_rate')
            ->filter(fn (?string $rate): bool => $rate !== null)
            ->map(fn (string $rate): float => (float) $rate)
            ->unique()
            ->sort()
            ->values();

        return [
            'disbursed_loan_count' => $disbursed->count(),
            'fully_paid_loan_count' => $fullyPaidCount,
            'has_active_loan' => $disbursed->count() > $fullyPaidCount,
            'all_loans_fully_paid' => $disbursed->isNotEmpty() && $fullyPaidCount === $disbursed->count(),
            'total_paid' => $totalPaid?->jsonSerialize(),
            'total_outstanding' => $totalOutstanding?->jsonSerialize(),
            'interest_rate' => $interestRates->count() === 1 ? $interestRates->first() : null,
            'interest_rates' => $interestRates->all(),
        ];
    }
}
