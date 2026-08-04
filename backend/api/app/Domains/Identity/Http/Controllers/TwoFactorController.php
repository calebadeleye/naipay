<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\ConfirmTwoFactorRequest;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Services\TwoFactorService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-factor enrolment and management for the signed-in staff member.
 *
 * Every action here operates on the caller's own account. There is no endpoint
 * for enrolling or disabling two-factor on someone else's account: an
 * administrator who could do that could take over any account in the system.
 */
final class TwoFactorController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Begin enrolment. Returns the secret and a QR code to scan.
     *
     * Two-factor is not active until `confirm` succeeds.
     */
    public function enrol(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $enrolment = $this->twoFactor->beginEnrolment($staff);

        return ApiResponse::success(
            data: $enrolment,
            message: 'Scan the QR code with your authenticator app, then confirm with a code.',
        );
    }

    /**
     * Confirm enrolment with a code, activating two-factor.
     *
     * Returns the recovery codes. This is the only time they are shown.
     */
    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $recoveryCodes = $this->twoFactor->confirm(
            $staff,
            $request->string('code')->toString(),
        );

        return ApiResponse::success(
            data: [
                'recovery_codes' => $recoveryCodes,
            ],
            message: 'Two-factor authentication is now enabled. Store your recovery codes somewhere safe — they will not be shown again.',
        );
    }

    /**
     * Turn two-factor off. Refused where the role makes it mandatory.
     */
    public function disable(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $this->twoFactor->disable($staff);

        return ApiResponse::success(message: 'Two-factor authentication has been disabled.');
    }

    /**
     * Issue a fresh set of recovery codes, invalidating the previous set.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $recoveryCodes = $this->twoFactor->regenerateRecoveryCodes($staff);

        return ApiResponse::success(
            data: ['recovery_codes' => $recoveryCodes],
            message: 'New recovery codes generated. Your previous codes no longer work.',
        );
    }

    /**
     * Current two-factor state, including how many recovery codes remain.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        return ApiResponse::success(
            data: [
                'enabled' => $staff->hasTwoFactorEnabled(),
                'required' => $staff->requiresTwoFactor(),
                'confirmed_at' => $staff->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => $this->twoFactor->remainingRecoveryCodes($staff),
            ],
            message: 'Two-factor status retrieved.',
        );
    }
}
