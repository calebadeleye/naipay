<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Controllers\Merchant;

use App\Domains\Businesses\Http\Requests\UpdateBusinessSelfRequest;
use App\Domains\Businesses\Http\Resources\BusinessResource;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Services\BusinessService;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant viewing and editing their own businesses through the
 * self-service portal.
 *
 * Route model binding resolves a business by id regardless of which merchant
 * owns it, so every single-business action re-checks ownership — the same
 * discipline the staff console applies for branch scope.
 */
final class BusinessController
{
    public function __construct(
        private readonly BusinessService $businesses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $businesses = $merchant->businesses()->with('category')->get();

        return ApiResponse::success(
            BusinessResource::collection($businesses)->resolve(),
            'Businesses retrieved.',
        );
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authoriseOwnership($request, $business);

        return ApiResponse::success(
            new BusinessResource($business->load('category')),
            'Business retrieved.',
        );
    }

    public function update(UpdateBusinessSelfRequest $request, Business $business): JsonResponse
    {
        $this->authoriseOwnership($request, $business);

        /** @var Merchant $merchant */
        $merchant = $request->user();

        $updated = $this->businesses->updateSelf($business, $request->validated(), $merchant);

        return ApiResponse::success(
            new BusinessResource($updated->load('category')),
            'Business updated.',
        );
    }

    private function authoriseOwnership(Request $request, Business $business): void
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        abort_unless(
            $business->merchant_id === $merchant->id,
            404,
            'The requested business was not found.',
        );
    }
}
