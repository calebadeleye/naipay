<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Resources\LoginAttemptResource;
use App\Domains\Identity\Http\Resources\SessionResource;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Services\AuthenticationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in operator's own sessions and login history.
 *
 * This is how someone notices that their credentials are being used somewhere
 * they do not recognise, and cuts it off themselves without waiting for an
 * administrator.
 */
final class SessionController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    /**
     * Active sessions, most recently used first.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $current = $staff->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $sessions = $staff->tokens()
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => new SessionResource(
                $token,
                isCurrent: $token->getKey() === $currentId,
            ));

        return ApiResponse::success(
            data: $sessions->all(),
            message: 'Active sessions retrieved.',
        );
    }

    /**
     * Revoke one session by id.
     *
     * Scoped to the caller's own tokens, so an id belonging to another staff
     * member simply is not found.
     */
    public function destroy(Request $request, int $session): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $deleted = $staff->tokens()->where('id', $session)->delete();

        if ($deleted === 0) {
            return ApiResponse::notFound('That session was not found.');
        }

        return ApiResponse::success(message: 'Session signed out.');
    }

    /**
     * Revoke every session except this one.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $count = $this->authentication->signOutOtherSessions($staff);

        return ApiResponse::success(
            data: ['sessions_revoked' => $count],
            message: $count === 0
                ? 'There were no other active sessions.'
                : "Signed out {$count} other session(s).",
        );
    }

    /**
     * The caller's own login history, successful and failed.
     */
    public function loginHistory(Request $request): JsonResponse
    {
        /** @var Staff $staff */
        $staff = $request->user();

        $perPage = min((int) $request->input('per_page', 25), 100);

        $attempts = $staff->loginAttempts()
            ->orderByDesc('attempted_at')
            ->paginate($perPage);

        return ApiResponse::paginated(
            $attempts->through(fn ($attempt) => new LoginAttemptResource($attempt)),
            message: 'Login history retrieved.',
        );
    }
}
