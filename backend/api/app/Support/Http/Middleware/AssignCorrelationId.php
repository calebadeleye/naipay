<?php

declare(strict_types=1);

namespace App\Support\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation ID that follows it through logs, queued
 * jobs and audit records, so a single merchant action can be traced end to end.
 *
 * An inbound `X-Correlation-Id` is honoured when it looks sane, which lets the
 * admin client tie a user interaction to whatever the backend did with it.
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolveIncoming($request) ?? (string) Str::uuid();

        Correlation::set($correlationId);
        $request->headers->set(self::HEADER, $correlationId);
        $request->attributes->set('correlation_id', $correlationId);

        Log::shareContext([
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Only accepts a client-supplied ID that is short and alphanumeric — an
     * unbounded header would otherwise end up in every log line and audit row.
     */
    private function resolveIncoming(Request $request): ?string
    {
        $incoming = $request->header(self::HEADER);

        if (! is_string($incoming) || $incoming === '') {
            return null;
        }

        $incoming = trim($incoming);

        if (strlen($incoming) > 64 || preg_match('/^[A-Za-z0-9\-_]+$/', $incoming) !== 1) {
            return null;
        }

        return $incoming;
    }
}
