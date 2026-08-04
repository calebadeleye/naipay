<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Support\Correlation;
use Illuminate\Support\Facades\Auth;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log record with the request identity.
 *
 * Combined with the audit log, this is what makes "who did this, and what else
 * happened as part of it" answerable after the fact.
 */
final class AttachRequestContext implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = [
            'correlation_id' => Correlation::id(),
            'application' => config('naipay.brand.name'),
            'environment' => config('app.env'),
        ];

        // Resolving the guard during console bootstrap can run before the auth
        // system is ready, so this is best-effort by design.
        if (Auth::hasResolvedGuards() && Auth::hasUser()) {
            $extra['actor_id'] = Auth::id();
        }

        if (($request = request()) !== null && $request->runningInConsole() === false) {
            $extra['method'] = $request->method();
            $extra['path'] = $request->path();
            $extra['ip'] = $request->ip();
        }

        return $record->with(extra: array_merge($record->extra, $extra));
    }
}
