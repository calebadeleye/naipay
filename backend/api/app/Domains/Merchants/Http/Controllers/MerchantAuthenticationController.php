<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Controllers;

use App\Domains\Identity\Support\RequestContext;
use App\Domains\Merchants\Http\Requests\MerchantLoginRequest;
use App\Domains\Merchants\Http\Resources\MerchantSelfResource;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Services\MerchantAuthenticationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in, sign-out and the current session for the merchant portal.
 *
 * Deliberately thinner than the staff AuthenticationController: no two-factor
 * step, no forced-password-change flow. Account activation and password
 * reset live in MerchantPasswordController.
 */
final class MerchantAuthenticationController
{
    public function __construct(
        private readonly MerchantAuthenticationService $authentication,
    ) {}

    public function login(MerchantLoginRequest $request): JsonResponse
    {
        $result = $this->authentication->attempt(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            RequestContext::fromRequest($request),
        );

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_in' => (int) config('naipay.security.token_lifetime_minutes', 480) * 60,
                'merchant' => new MerchantSelfResource($result['merchant']->load(['account', 'businesses'])),
            ],
            message: 'Signed in successfully.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        return ApiResponse::success(
            data: new MerchantSelfResource($merchant->load(['account', 'businesses'])),
            message: 'Current merchant retrieved.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $this->authentication->signOut($merchant);

        return ApiResponse::success(message: 'You have been signed out.');
    }
}
