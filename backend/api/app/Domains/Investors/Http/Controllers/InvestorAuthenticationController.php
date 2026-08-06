<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Controllers;

use App\Domains\Identity\Support\RequestContext;
use App\Domains\Investors\Http\Requests\InvestorLoginRequest;
use App\Domains\Investors\Http\Resources\InvestorResource;
use App\Domains\Investors\Models\Investor;
use App\Domains\Investors\Services\InvestorAuthenticationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in, sign-out and the current session for the investor portal.
 *
 * Deliberately thinner than AuthenticationController: no two-factor step and
 * no forced-password-change flow, since an investor session only ever grants
 * read access to one dashboard.
 */
final class InvestorAuthenticationController
{
    public function __construct(
        private readonly InvestorAuthenticationService $authentication,
    ) {}

    public function login(InvestorLoginRequest $request): JsonResponse
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
                'investor' => new InvestorResource($result['investor']),
            ],
            message: 'Signed in successfully.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Investor $investor */
        $investor = $request->user();

        return ApiResponse::success(
            data: new InvestorResource($investor),
            message: 'Current investor retrieved.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var Investor $investor */
        $investor = $request->user();

        $this->authentication->signOut($investor);

        return ApiResponse::success(message: 'You have been signed out.');
    }
}
