<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Raised when an operation in naipay.security.reauthentication_required_operations
 * is attempted without a recent enough step-up authentication.
 *
 * 428 Precondition Required: the request is otherwise valid — the actor holds
 * the permission and passes maker-checker — but a precondition (proving it is
 * still them, at an unattended workstation, a second time) has not been met.
 */
final class ReauthenticationRequiredException extends DomainException
{
    public function __construct(string $operation)
    {
        parent::__construct(
            message: 'This action requires you to confirm your password (and two-factor code, if enabled) again before continuing.',
            errors: [],
            status: HttpStatus::HTTP_PRECONDITION_REQUIRED,
            context: ['operation' => $operation],
        );
    }
}
