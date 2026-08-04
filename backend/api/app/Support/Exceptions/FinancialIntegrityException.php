<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * Raised when an invariant that protects financial truth is violated — an
 * unbalanced journal transaction, a duplicate posting, a negative balance where
 * none is permissible.
 *
 * Always accompanies a rolled-back transaction. These are logged at critical
 * level and should page someone: they indicate a defect, not user error.
 */
final class FinancialIntegrityException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, array $context = [], ?Throwable $previous = null)
    {
        parent::__construct(
            message: $message,
            errors: [],
            status: HttpStatus::HTTP_CONFLICT,
            context: $context,
            previous: $previous,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unbalanced(string $debits, string $credits, array $context = []): self
    {
        return new self(
            "Journal transaction does not balance: debits {$debits} against credits {$credits}.",
            array_merge($context, ['total_debits' => $debits, 'total_credits' => $credits]),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function duplicatePosting(string $idempotencyKey, array $context = []): self
    {
        return new self(
            'A financial transaction has already been posted for this operation.',
            array_merge($context, ['idempotency_key' => $idempotencyKey]),
        );
    }
}
