<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Correlation;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Translates every exception into the standard Naipay error envelope.
 *
 * The guiding rule is that a client learns what it needs to correct the request
 * and nothing more. Internal failures return a generic message plus the
 * correlation ID, so an operator can quote that ID to support and an engineer
 * can find the full trace in the logs without any of it being exposed over the
 * wire.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $this->shouldHandle($request)) {
            return null;
        }

        // Some exceptions carry the response they want returned — the throttle
        // middleware's custom 429 among them. Handing these back to Laravel is
        // the only correct move; treating them as unhandled would turn a
        // deliberate 429 into a 500.
        if ($e instanceof HttpResponseException) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::validationError($e->errors(), $e->getMessage()),

            $e instanceof AuthenticationException => ApiResponse::unauthorized(
                'Your session has expired or is not valid. Please sign in again.'
            ),

            $e instanceof AuthorizationException => ApiResponse::forbidden(
                $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                    ? $e->getMessage()
                    : 'You do not have permission to perform this action.'
            ),

            $e instanceof TokenMismatchException => ApiResponse::error(
                'The security token has expired. Please refresh and try again.',
                status: 419,
            ),

            // Handled before the generic HttpException arm so the domain
            // message and field errors survive.
            $e instanceof DomainException => $this->renderDomainException($e),

            $e instanceof ModelNotFoundException => ApiResponse::notFound(
                $this->missingResourceMessage($e)
            ),

            // A unique-constraint violation reaching this far means a request
            // slipped past validation (an auto-generated value colliding, a
            // race between two requests). The operator still deserves a clean
            // message instead of a raw SQL error.
            $e instanceof QueryException && $this->isDuplicateEntry($e) => $this->renderDuplicateEntry($e),

            // Thrown both when no route matches (an empty message from
            // Symfony, or Laravel's own "The route <uri> could not be
            // found.") and by an application-level `abort(404, '...')`, which
            // carries a real, meaningful message — e.g. a merchant outside the
            // caller's branch. Only the route-miss should say "endpoint does
            // not exist"; a deliberate not-found message must survive verbatim,
            // or it looks like a broken route to whoever reads it.
            $e instanceof NotFoundHttpException => ApiResponse::notFound(
                $this->isRouteMiss($e) ? 'The requested endpoint does not exist.' : $e->getMessage()
            ),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'That method is not supported for this endpoint.',
                status: 405,
            ),

            $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                'Too many requests. Please wait a moment and try again.',
                status: 429,
            ),

            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.',
                status: $e->getStatusCode(),
            ),

            default => $this->renderUnexpected($e),
        };
    }

    private function renderDomainException(DomainException $e): JsonResponse
    {
        // A broken financial invariant is a defect, not user error — record it
        // at a level that will be alerted on before returning the 409.
        if ($e instanceof FinancialIntegrityException) {
            Log::critical('Financial integrity violation.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'context' => $e->context(),
                'correlation_id' => Correlation::id(),
            ]);
        } elseif ($e->context() !== []) {
            Log::warning($e->getMessage(), [
                'exception' => $e::class,
                'context' => $e->context(),
                'correlation_id' => Correlation::id(),
            ]);
        }

        return ApiResponse::error($e->getMessage(), $e->errors(), $e->status());
    }

    /**
     * A NotFoundHttpException that means "nothing routes here", as opposed to
     * a deliberate abort(404, 'a real message'). Symfony raises it with an
     * empty message; Laravel's router raises it as "The route <uri> could not
     * be found." Both are route misses and neither message should reach a
     * caller.
     */
    private function isRouteMiss(NotFoundHttpException $e): bool
    {
        return $e->getMessage() === ''
            || preg_match('/^The route .+ could not be found\.$/', $e->getMessage()) === 1;
    }

    private function isDuplicateEntry(QueryException $e): bool
    {
        // 1062 is MySQL's "Duplicate entry" — the only integrity violation
        // that maps to something a user caused and can fix.
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private function renderDuplicateEntry(QueryException $e): JsonResponse
    {
        Log::warning('Unique constraint violated.', [
            'message' => $e->getMessage(),
            'correlation_id' => Correlation::id(),
        ]);

        $field = $this->duplicateField($e);

        // Returned as a conflict rather than a field-level validation error:
        // the colliding column (a generated reference like a branch code, for
        // instance) is not always one a form even exposes, so a message tied
        // to a field nobody can see would render invisibly. A top-level
        // conflict always surfaces.
        return ApiResponse::conflict(
            $field !== null
                ? 'This '.str_replace('_', ' ', $field).' is already in use. Please try again.'
                : 'This could not be saved because it duplicates an existing record.',
        );
    }

    /**
     * Best-effort mapping from the unique key MySQL names in its error
     * message back to the column that violated it, purely to name it in the
     * message above. Relies on Laravel's default `{table}_{column}_unique`
     * naming — falls back to a generic message when a key doesn't follow it.
     */
    private function duplicateField(QueryException $e): ?string
    {
        if (! preg_match("/for key '(?:\w+\.)?(\w+)'/", $e->getMessage(), $keyMatch)) {
            return null;
        }

        $key = $keyMatch[1];

        if (! preg_match('/`(\w+)`\s*\(/', $e->getSql() ?? '', $tableMatch)) {
            return null;
        }

        $column = preg_replace(
            ['/^'.preg_quote($tableMatch[1].'_', '/').'/', '/_unique$/'],
            '',
            $key,
        );

        return $column !== '' && $column !== $key ? $column : null;
    }

    /**
     * Names the resource type but never echoes the identifier that was looked
     * up, which keeps the endpoint from confirming whether a given record
     * exists to someone enumerating IDs.
     */
    private function missingResourceMessage(ModelNotFoundException $e): string
    {
        $model = class_basename($e->getModel());

        $readable = trim(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $model) ?? $model));

        return "The requested {$readable} was not found.";
    }

    private function renderUnexpected(Throwable $e): JsonResponse
    {
        $correlationId = Correlation::id();

        Log::error('Unhandled exception.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'correlation_id' => $correlationId,
        ]);

        // Stack traces and internal messages are shown only when debugging is
        // deliberately enabled, which is never the case in production.
        if (config('app.debug') === true) {
            return ApiResponse::error(
                $e->getMessage(),
                [
                    'exception' => [$e::class],
                    'location' => [$e->getFile().':'.$e->getLine()],
                ],
                500,
            );
        }

        return ApiResponse::error(
            "An unexpected error occurred. Quote reference {$correlationId} when contacting support.",
            status: 500,
        );
    }

    private function shouldHandle(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
