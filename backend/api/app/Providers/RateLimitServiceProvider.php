<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Http\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limits, backed by Redis.
 *
 * Authenticated staff are limited per user so one officer running a heavy
 * report cannot exhaust the branch's shared allowance. Unauthenticated traffic
 * is limited per IP and much more tightly, since the only endpoints reachable
 * without a token are authentication ones.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerGeneralApiLimit();
        $this->registerAuthenticationLimit();
        $this->registerExportLimit();
    }

    private function registerGeneralApiLimit(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(300)->by('user:'.$user->getAuthIdentifier())
                : Limit::perMinute(60)->by('ip:'.$request->ip());
        });
    }

    /**
     * Throttles credential-guessing at the transport layer. This sits in front
     * of, and is independent from, the per-account lockout in the auth domain:
     * this one stops an attacker spraying many accounts from one source.
     */
    private function registerAuthenticationLimit(): void
    {
        RateLimiter::for('authentication', function (Request $request): array {
            $identifier = (string) $request->input('email', $request->input('username', ''));

            return [
                Limit::perMinute(5)
                    ->by('auth:'.mb_strtolower($identifier).'|'.$request->ip())
                    ->response(fn (): mixed => ApiResponse::error(
                        'Too many sign-in attempts. Please wait before trying again.',
                        status: 429,
                    )),

                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Exports are expensive and land on the queue; a low ceiling keeps a
     * mis-clicked report from flooding the workers.
     */
    private function registerExportLimit(): void
    {
        RateLimiter::for('exports', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by(
                $user !== null ? 'export:'.$user->getAuthIdentifier() : 'export-ip:'.$request->ip()
            );
        });
    }
}
