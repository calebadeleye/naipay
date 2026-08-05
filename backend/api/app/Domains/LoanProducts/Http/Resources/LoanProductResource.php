<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Http\Resources;

use App\Domains\LoanProducts\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanProduct
 */
final class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoanProduct $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'description' => $product->description,
            'summary' => $product->summary(),

            'amount' => [
                'minimum' => $product->minimum_amount->jsonSerialize(),
                'maximum' => $product->maximum_amount->jsonSerialize(),
            ],

            'tenor' => [
                'minimum' => $product->minimum_tenor,
                'maximum' => $product->maximum_tenor,
                'default' => $product->default_tenor,
                'unit' => $product->tenor_unit->value,
                'unit_label' => $product->tenor_unit->label(),
            ],

            'interest' => [
                // A decimal string, not a float: this multiplies principal.
                'rate' => $product->interest_rate,
                'method' => $product->interest_method->value,
                'method_label' => $product->interest_method->label(),
                'method_description' => $product->interest_method->description(),
                'varies_with_term' => $product->interest_method->variesWithTerm(),
                'period' => $product->interest_period,
            ],

            'repayment' => [
                'frequency' => $product->repayment_frequency->value,
                'frequency_label' => $product->repayment_frequency->label(),
                // Daily collection runs Monday to Friday only.
                'skips_weekends' => $product->repayment_frequency->skipsWeekends(),
                'grace_period_days' => $product->grace_period_days,
            ],

            'fees' => [
                'processing' => [
                    'type' => $product->processing_fee_type->value,
                    'value' => $product->processing_fee_value?->jsonSerialize(),
                ],
                'insurance' => [
                    'type' => $product->insurance_fee_type->value,
                    'value' => $product->insurance_fee_value?->jsonSerialize(),
                ],
                'late_payment_penalty' => [
                    'type' => $product->late_payment_penalty_type->value,
                    'value' => $product->late_payment_penalty_value?->jsonSerialize(),
                ],
            ],

            'requirements' => [
                'requires_guarantor' => $product->requires_guarantor,
                'minimum_guarantors' => $product->minimum_guarantors,
                'requires_collateral' => $product->requires_collateral,
            ],

            'status' => $product->status,
            'is_active' => $product->isActive(),
            'display_order' => $product->display_order,

            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
