<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Middleware;

use App\Domains\Identity\Models\AccessToken;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminates an administrative session that has been idle too long.
 *
 * Sanctum's own `expires_at` gives an absolute ceiling on a token's life. This
 * adds the idle timeout on top: a branch workstation left unattended must not
 * stay authenticated for the rest of the shift.
 *
 * The token is deleted rather than just rejected, so a stolen bearer token
 * cannot be replayed later.
 */
final class EnforceSessionExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if (! $token instanceof AccessToken) {
            return $next($request);
        }

        $idleMinutes = (int) config('naipay.security.session_idle_minutes', 30);

        if ($token->idleMinutes() >= $idleMinutes) {
            $token->delete();

            return ApiResponse::unauthorized(
                'Your session ended after a period of inactivity. Please sign in again.'
            );
        }

        $response = $next($request);

        // Only a persisted token has activity worth recording. Sanctum's test
        // double and its transient token have no row behind them.
        if ($token->exists !== true) {
            return $response;
        }

        $lastActivity = $token->lastActivity();

        // Written after the request succeeds, and only once a minute, so an
        // operator working through a list does not generate a write per
        // keystroke-driven request.
        if ($lastActivity === null || $lastActivity->diffInSeconds(now(), absolute: true) >= 60) {
            $token->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
