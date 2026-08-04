<?php

declare(strict_types=1);

namespace App\Domains\Approvals\Services;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\MakerCheckerViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Enforces segregation of duties: a staff member may never approve an
 * operation they created.
 *
 * Centralised on purpose. This control is only as strong as its least careful
 * call site, and "the approving controller forgot to check" is exactly how it
 * fails in practice. Every approval path calls `assertCanApprove()` before it
 * changes state, and the enforced operations are configuration rather than
 * code so the business can extend the list without a deployment.
 *
 * Note this is a floor, not the whole control. It is meaningful only because
 * the role matrix also keeps both halves of each workflow out of one role's
 * hands — a Cashier records repayments but cannot approve any, so there is
 * nothing to bypass.
 */
final class MakerCheckerGuard
{
    /**
     * @throws MakerCheckerViolationException
     */
    public function assertCanApprove(Staff $approver, RequiresMakerChecker $record): void
    {
        if ($this->canApprove($approver, $record)) {
            return;
        }

        // An attempt is itself worth seeing: it is either a workflow the staff
        // member misunderstands, or a deliberate probe.
        Log::warning('Maker-checker violation refused.', [
            'operation' => $record->makerCheckerOperation(),
            'approver_id' => $approver->getKey(),
            'maker_id' => $record->makerId(),
            'record' => $record::class,
        ]);

        throw new MakerCheckerViolationException(
            $record->makerCheckerOperation(),
            (int) $approver->getKey(),
        );
    }

    public function canApprove(Staff $approver, RequiresMakerChecker $record): bool
    {
        if (! $this->isEnforcedFor($record->makerCheckerOperation())) {
            return true;
        }

        $makerId = $record->makerId();

        // System-generated records have no maker to conflict with.
        if ($makerId === null) {
            return true;
        }

        return $makerId !== (int) $approver->getKey();
    }

    /**
     * Whether an operation key is subject to the control.
     */
    public function isEnforcedFor(string $operation): bool
    {
        /** @var array<int, string> $enforced */
        $enforced = config('naipay.maker_checker.enforced_operations', []);

        return in_array($operation, $enforced, true);
    }

    /**
     * Whether an operation requires the actor to re-authenticate at the point
     * of action, regardless of an active session.
     *
     * Reserved for the operations where an unattended workstation would
     * otherwise be enough to move money.
     */
    public function requiresReauthentication(string $operation): bool
    {
        /** @var array<int, string> $operations */
        $operations = config('naipay.security.reauthentication_required_operations', []);

        return in_array($operation, $operations, true);
    }
}
