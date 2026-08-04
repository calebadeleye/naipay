<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Raised when a staff member attempts to approve an operation they created.
 *
 * Segregation of duties is a control the auditor tests, so this is refused at
 * the service layer and recorded — an attempt is itself worth seeing.
 */
final class MakerCheckerViolationException extends DomainException
{
    public function __construct(string $operation, int $actorId)
    {
        parent::__construct(
            message: 'You cannot approve an operation that you created. A different authorised officer must review it.',
            errors: [],
            status: HttpStatus::HTTP_FORBIDDEN,
            context: ['operation' => $operation, 'actor_id' => $actorId],
        );
    }
}
