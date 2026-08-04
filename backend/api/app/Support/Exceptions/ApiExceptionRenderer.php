<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Correlation;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

            $e instanceof NotFoundHttpException => ApiResponse::notFound(
                'The requested endpoint does not exist.'
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
