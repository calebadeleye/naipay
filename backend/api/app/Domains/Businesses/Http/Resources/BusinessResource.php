<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Resources;

use App\Domains\Businesses\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Business
 */
final class BusinessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource;

        return [
            'id' => $business->id,
            'business_number' => $business->business_number,
            'merchant_id' => $business->merchant_id,

            'business_name' => $business->business_name,
            'registered_business_name' => $business->registered_business_name,
            'cac_registration_number' => $business->cac_registration_number,

            'business_type' => $business->business_type->value,
            'business_type_label' => $business->business_type->label(),
            'requires_cac_registration' => $business->business_type->requiresCacRegistration(),
            'has_outstanding_registration' => $business->hasOutstandingRegistration(),

            'business_description' => $business->business_description,

            'category' => $business->relationLoaded('category') && $business->category !== null ? [
                'id' => $business->category->id,
                'name' => $business->category->name,
            ] : null,

            'subcategory' => $business->relationLoaded('subcategory') && $business->subcategory !== null ? [
                'id' => $business->subcategory->id,
                'name' => $business->subcategory->name,
            ] : null,

            'business_phone' => $business->business_phone,
            'business_email' => $business->business_email,
            'business_address' => $business->business_address,
            'city' => $business->city,
            'state' => $business->state,
            'country' => $business->country,
            'business_website' => $business->business_website,
            'social_media_links' => $business->social_media_links,

            'gps_latitude' => $business->gps_latitude,
            'gps_longitude' => $business->gps_longitude,

            'year_established' => $business->year_established,
            'number_of_employees' => $business->number_of_employees,

            // Exact decimal strings with a formatted display value; never a
            // float, because these feed repayment-capacity assessment.
            'estimated_monthly_revenue' => $business->estimated_monthly_revenue?->jsonSerialize(),
            'estimated_monthly_expenses' => $business->estimated_monthly_expenses?->jsonSerialize(),
            'average_monthly_sales' => $business->average_monthly_sales?->jsonSerialize(),
            'declared_monthly_surplus' => $business->declaredMonthlySurplus()?->jsonSerialize(),

            'verification_status' => $business->verification_status->value,
            'verification_status_label' => $business->verification_status->label(),
            'is_verified' => $business->isVerified(),

            'status' => $business->status->value,
            'status_label' => $business->status->label(),
            'is_operational' => $business->isOperational(),

            /*
             * Business-connection state is reported but not settable by any
             * endpoint in this release. A public profile must never appear
             * without the merchant's explicit consent.
             */
            'connections' => [
                'public_profile_enabled' => $business->public_profile_enabled,
                'accepts_business_connections' => $business->accepts_business_connections,
                'visibility' => $business->business_visibility,
            ],

            'approved_at' => $business->approved_at?->toIso8601String(),
            'created_at' => $business->created_at?->toIso8601String(),
            'updated_at' => $business->updated_at?->toIso8601String(),
        ];
    }
}
