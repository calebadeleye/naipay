<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\ChangePasswordRequest;
use App\Domains\Identity\Http\Requests\ForgotPasswordRequest;
use App\Domains\Identity\Http\Requests\ResetPasswordRequest;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Services\PasswordService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PasswordController
{
    public function __construct(
        private readonly PasswordService $passwords,
    ) {}

    /**
     * Change the signed-in operator's own password.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $this->passwords->change(
            $staff,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(
            message: 'Your password has been changed. Other sessions have been signed out.',
        );
    }

    /**
     * Request a reset link.
     *
     * Always reports success, whether or not the address matched an account.
     * Anything else makes this an account-enumeration oracle, and it is
     * reachable without authentication.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink($request->string('email')->toString());

        return ApiResponse::success(
            message: 'If that email address matches a Naipay account, a reset link has been sent to it.',
        );
    }

    /**
     * Complete a reset using the emailed token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return ApiResponse::success(
            message: 'Your password has been reset. Sign in with your new password.',
        );
    }
}
