<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Builds every JSON response Naipay emits.
 *
 * The envelope is fixed and must not vary between endpoints — the admin web
 * client, and later the merchant portal and mobile app, all decode the same
 * shape:
 *
 *   success: { success, message, data, meta }
 *   failure: { success, message, errors }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'Request completed successfully.',
        array $meta = [],
        int $status = HttpStatus::HTTP_OK,
    ): JsonResponse {
        return new JsonResponse([
            'success' => true,
            'message' => $message,
            'data' => self::normaliseData($data),
            'meta' => (object) $meta,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(
        mixed $data = null,
        string $message = 'Resource created successfully.',
        array $meta = [],
    ): JsonResponse {
        return self::success($data, $message, $meta, HttpStatus::HTTP_CREATED);
    }

    public static function noContent(string $message = 'Request completed successfully.'): JsonResponse
    {
        return self::success(null, $message);
    }

    /**
     * Wraps a paginator, lifting pagination details into `meta` so `data` stays
     * a plain array the client can iterate without unwrapping.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $message = 'Request completed successfully.',
        array $meta = [],
    ): JsonResponse {
        $items = $paginator->items();

        return self::success(
            self::normaliseData($items),
            $message,
            array_merge($meta, [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more_pages' => $paginator->hasMorePages(),
                ],
            ]),
        );
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function error(
        string $message,
        array $errors = [],
        int $status = HttpStatus::HTTP_BAD_REQUEST,
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        // `errors` is only meaningful for field-level failures. Omitting it for
        // everything else keeps clients from rendering an empty error list.
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed.',
    ): JsonResponse {
        return self::error($message, $errors, HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function unauthorized(string $message = 'Authentication is required.'): JsonResponse
    {
        return self::error($message, [], HttpStatus::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(string $message = 'You are not authorised to perform this action.'): JsonResponse
    {
        return self::error($message, [], HttpStatus::HTTP_FORBIDDEN);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): JsonResponse
    {
        return self::error($message, [], HttpStatus::HTTP_NOT_FOUND);
    }

    public static function conflict(string $message): JsonResponse
    {
        return self::error($message, [], HttpStatus::HTTP_CONFLICT);
    }

    public static function serverError(string $message = 'An unexpected error occurred.'): JsonResponse
    {
        return self::error($message, [], HttpStatus::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Resolves resources/collections to arrays so `data` is always plain JSON,
     * and coerces an empty payload to `{}` rather than `null` — clients treat
     * `data` as an object and should not have to null-check it.
     */
    private static function normaliseData(mixed $data): mixed
    {
        if ($data === null) {
            return (object) [];
        }

        if ($data instanceof ResourceCollection || $data instanceof JsonResource) {
            $resolved = $data->resolve();

            return Arr::isList($resolved) ? $resolved : (object) $resolved;
        }

        if (is_array($data) && $data === []) {
            return [];
        }

        return $data;
    }
}
