<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Http\Requests\ReauthenticateRequest;
use App\Domains\Identity\Http\Requests\SendTwoFactorEmailCodeRequest;
use App\Domains\Identity\Http\Requests\TwoFactorChallengeRequest;
use App\Domains\Identity\Http\Resources\StaffResource;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Services\AuthenticationService;
use App\Domains\Identity\Support\RequestContext;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sign-in, two-factor challenge, sign-out and the current session.
 *
 * Controllers here do no work beyond translating HTTP to a service call — the
 * sequencing that has to be right lives in AuthenticationService.
 */
final class AuthenticationController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    /**
     * Verify credentials and either issue a token or open a two-factor
     * challenge.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authentication->attempt(
            $request->string('identifier')->toString(),
            $request->string('password')->toString(),
            RequestContext::fromRequest($request),
        );

        if ($result->requiresTwoFactor()) {
            return ApiResponse::success(
                data: [
                    'requires_two_factor' => true,
                    'challenge_token' => $result->challengeToken,
                    'expires_in' => $result->challengeExpiresInSeconds,
                ],
                message: 'Enter the verification code from your authenticator app.',
            );
        }

        return $this->respondWithSession($result->staff, (string) $result->token);
    }

    /**
     * Answer a two-factor challenge with a TOTP or recovery code.
     */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        $result = $this->authentication->completeTwoFactorChallenge(
            $request->string('challenge_token')->toString(),
            $request->string('code')->toString(),
            RequestContext::fromRequest($request),
        );

        return $this->respondWithSession($result->staff, (string) $result->token);
    }

    /**
     * Mails a one-time code for a pending two-factor challenge, for an
     * operator without their authenticator app to hand.
     */
    public function sendTwoFactorEmailCode(SendTwoFactorEmailCodeRequest $request): JsonResponse
    {
        $this->authentication->sendTwoFactorEmailCode($request->string('challenge_token')->toString());

        return ApiResponse::success(message: 'A verification code has been sent to your email.');
    }

    /**
     * The signed-in staff member, their roles and their permissions.
     *
     * The console calls this on load to build navigation and decide which
     * actions to render.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        return ApiResponse::success(
            data: new StaffResource($staff),
            message: 'Current staff member retrieved.',
        );
    }

    /**
     * Proves the caller is still who they say they are, ahead of a sensitive
     * action naipay.security.reauthentication_required_operations names.
     */
    public function reauthenticate(ReauthenticateRequest $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $this->authentication->reauthenticate(
            $staff,
            $request->string('password')->toString(),
            $request->filled('code') ? $request->string('code')->toString() : null,
            RequestContext::fromRequest($request),
        );

        return ApiResponse::success(message: 'Reauthentication successful.');
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $this->authentication->signOut($staff);

        return ApiResponse::success(message: 'You have been signed out.');
    }

    private function respondWithSession(Staff $staff, string $token): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => (int) config('naipay.security.token_lifetime_minutes', 480) * 60,
                'staff' => new StaffResource($staff),
                // Surfaced so the console can route straight to the step that
                // must be completed before anything else will succeed.
                'required_action' => match (true) {
                    $staff->must_change_password => 'change_password',
                    $staff->requiresTwoFactor() && ! $staff->hasTwoFactorEnabled() => 'enrol_two_factor',
                    default => null,
                },
            ],
            message: 'Signed in successfully.',
        );
    }
}
