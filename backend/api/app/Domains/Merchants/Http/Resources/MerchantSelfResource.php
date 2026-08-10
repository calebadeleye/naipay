<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Resources;

use App\Domains\Businesses\Http\Resources\BusinessResource;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merchant's own account, as seen through the self-service portal.
 *
 * Distinct from MerchantResource, which is what staff see managing a
 * merchant. This one never carries an unmasked BVN/NIN (a merchant sees the
 * same masked value staff without merchants.view_sensitive would), and drops
 * everything that is purely internal organisational detail — branch,
 * assigned officer, risk rating. A merchant editing their own record has no
 * use for who their loan officer is assigned as, and a risk rating is a
 * credit judgement about them, not information for them.
 *
 * @mixin Merchant
 */
final class MerchantSelfResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Merchant $merchant */
        $merchant = $this->resource;

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
            ],

            'profile_photo' => $merchant->profile_photo,

            'onboarding_status' => $merchant->onboarding_status->value,
            'onboarding_status_label' => $merchant->onboarding_status->label(),

            'merchant_status' => $merchant->merchant_status->value,
            'merchant_status_label' => $merchant->merchant_status->label(),

            'kyc_status' => $merchant->kyc_status->value,
            'kyc_status_label' => $merchant->kyc_status->label(),

            'can_borrow' => $merchant->canBorrow(),

            'rejection_reason' => $merchant->rejection_reason,
            'suspension_reason' => $merchant->suspension_reason,

            'account' => $merchant->relationLoaded('account') && $merchant->account !== null ? [
                'account_number' => $merchant->account->account_number,
                'account_number_formatted' => $merchant->account->formattedAccountNumber(),
                'account_name' => $merchant->account->account_name,
                'currency' => $merchant->account->currency,
                'available_balance' => $merchant->account->available_balance?->jsonSerialize(),
            ] : null,

            'businesses' => BusinessResource::collection($this->whenLoaded('businesses')),

            'activated_at' => $merchant->activated_at?->toIso8601String(),
            'last_login_at' => $merchant->last_login_at?->toIso8601String(),

            'created_at' => $merchant->created_at?->toIso8601String(),
        ];
    }
}
