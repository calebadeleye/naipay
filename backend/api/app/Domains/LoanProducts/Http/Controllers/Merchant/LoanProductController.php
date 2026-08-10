<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Http\Controllers\Merchant;

use App\Domains\LoanProducts\Models\LoanProduct;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The loan products a merchant can choose from when applying for a loan.
 *
 * Deliberately minimal — just what the application form needs to build a
 * picker and validate an amount/tenor client-side before submitting. Product
 * configuration (rates, limits, guarantor rules) stays a staff-only concern
 * everywhere else; this is the one read-only exception a self-service
 * applicant needs.
 */
final class LoanProductController
{
    public function index(): JsonResponse
    {
        $products = LoanProduct::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (LoanProduct $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'minimum_amount' => $product->minimum_amount->jsonSerialize(),
                'maximum_amount' => $product->maximum_amount->jsonSerialize(),
                'minimum_tenor' => $product->minimum_tenor,
                'maximum_tenor' => $product->maximum_tenor,
                'tenor_unit' => $product->tenor_unit->value,
                'tenor_unit_label' => $product->tenor_unit->label(),
                'interest_rate' => $product->interest_rate,
                'requires_guarantor' => $product->requires_guarantor,
                'minimum_guarantors' => $product->minimum_guarantors,
                'summary' => $product->summary(),
            ]);

        return ApiResponse::success($products->all(), 'Loan products retrieved.');
    }
}
