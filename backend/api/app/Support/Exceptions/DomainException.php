<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Base class for a business-rule violation.
 *
 * Distinct from an infrastructure failure: these carry a message intended for
 * an operator ("This repayment has already been approved") and render as a
 * clean 4xx rather than being swallowed into a generic 500.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        protected readonly array $errors = [],
        protected readonly int $status = HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
        protected readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Extra detail written to the log but never returned to the client.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
