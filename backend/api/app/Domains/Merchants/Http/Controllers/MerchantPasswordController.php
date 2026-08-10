<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Controllers;

use App\Domains\Merchants\Http\Requests\ActivateMerchantAccountRequest;
use App\Domains\Merchants\Http\Requests\ForgotMerchantPasswordRequest;
use App\Domains\Merchants\Http\Requests\RequestMerchantActivationRequest;
use App\Domains\Merchants\Http\Requests\ResetMerchantPasswordRequest;
use App\Domains\Merchants\Services\MerchantPasswordService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Merchant portal account activation and password reset.
 *
 * Every action here reports success unconditionally, whether or not the
 * email address matched an account — see MerchantPasswordService for why.
 */
final class MerchantPasswordController
{
    public function __construct(
        private readonly MerchantPasswordService $passwords,
    ) {}

    public function requestActivation(RequestMerchantActivationRequest $request): JsonResponse
    {
        $this->passwords->requestActivation($request->string('email')->toString());

        return ApiResponse::success(
            message: 'If that email address matches an Every Merchant account ready to activate, a link has been sent to it.',
        );
    }

    public function activate(ActivateMerchantAccountRequest $request): JsonResponse
    {
        $this->passwords->complete(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(
            message: 'Your account has been activated. Sign in with your new password.',
        );
    }

    public function forgot(ForgotMerchantPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink($request->string('email')->toString());

        return ApiResponse::success(
            message: 'If that email address matches an Every Merchant account, a reset link has been sent to it.',
        );
    }

    public function reset(ResetMerchantPasswordRequest $request): JsonResponse
    {
        $this->passwords->complete(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(
            message: 'Your password has been reset. Sign in with your new password.',
        );
    }
}
