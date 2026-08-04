<?php

declare(strict_types=1);

namespace App\Support\Http\Controllers;

use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Liveness and dependency check for the load balancer and uptime monitoring.
 *
 * Reports degraded rather than failing outright when a non-critical dependency
 * is down, so a transient cache blip does not pull the whole service out of the
 * pool. A database failure is fatal — the API cannot serve anything useful.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->check(function (): bool {
                Cache::store()->put('naipay:health', '1', 10);

                return Cache::store()->get('naipay:health') === '1';
            }),
            'redis' => $this->check(fn () => Redis::connection()->ping() !== null),
        ];

        $databaseUp = $checks['database']['status'] === 'up';
        $allUp = ! in_array(false, array_map(
            static fn (array $check): bool => $check['status'] === 'up',
            $checks,
        ), true);

        return ApiResponse::success(
            data: [
                'status' => $allUp ? 'healthy' : ($databaseUp ? 'degraded' : 'unhealthy'),
                'checks' => $checks,
            ],
            message: 'Naipay API health check.',
            meta: [
                'application' => config('naipay.brand.name'),
                'environment' => config('app.env'),
                'version' => config('app.version'),
                'time' => now()->toIso8601String(),
            ],
            status: $databaseUp ? 200 : 503,
        );
    }

    /**
     * @return array{status: string, error?: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['status' => $probe() ? 'up' : 'down'];
        } catch (Throwable $e) {
            // The class name alone is enough to triage without leaking a DSN
            // or credentials through the health endpoint.
            return ['status' => 'down', 'error' => class_basename($e)];
        }
    }
}
