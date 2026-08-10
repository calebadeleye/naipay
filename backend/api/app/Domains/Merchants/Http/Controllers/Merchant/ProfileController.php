<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Controllers\Merchant;

use App\Domains\Merchants\Http\Requests\UpdateMerchantSelfRequest;
use App\Domains\Merchants\Http\Resources\MerchantSelfResource;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Services\MerchantOnboardingService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant viewing and editing their own profile through the self-service
 * portal.
 *
 * Ownership is implicit throughout: the subject is always `$request->user()`,
 * never a route parameter, so there is nothing to scope-check.
 */
final class ProfileController
{
    public function __construct(
        private readonly MerchantOnboardingService $merchants,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return ApiResponse::success(
            new MerchantSelfResource($merchant->load(['account', 'businesses'])),
            'Profile retrieved.',
        );
    }

    public function update(UpdateMerchantSelfRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $updated = $this->merchants->updateSelf($merchant, $request->validated());

        return ApiResponse::success(
            new MerchantSelfResource($updated->load(['account', 'businesses'])),
            'Profile updated.',
        );
    }
}
