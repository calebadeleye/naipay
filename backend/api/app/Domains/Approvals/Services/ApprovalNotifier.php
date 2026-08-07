<?php

declare(strict_types=1);

namespace App\Domains\Approvals\Services;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Approvals\Notifications\ApprovalDecisionNotification;
use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells a record's maker what happened once a checker (or the maker
 * themself, for a rejection with no maker-checker control) decides it.
 *
 * Deliberately separate from `MakerCheckerGuard`: the guard answers "is this
 * actor allowed to act", called before a decision is made, while this is
 * called after one is made — including for a rejection, which never goes
 * through the guard at all.
 */
final class ApprovalNotifier
{
    public function notifyDecision(
        RequiresMakerChecker $record,
        string $decision,
        string $subjectReference,
        string $actionUrl,
        Staff $actor,
        ?Model $subject = null,
        ?string $reason = null,
    ): void {
        $makerId = $record->makerId();

        if ($makerId === null) {
            return;
        }

        /** @var Staff|null $maker */
        $maker = Staff::query()->find($makerId);

        if ($maker === null || $maker->is($actor)) {
            return;
        }

        $maker->notify(new ApprovalDecisionNotification(
            operation: $record->makerCheckerOperation(),
            subjectReference: $subjectReference,
            decision: $decision,
            actorName: $actor->fullName(),
            actionUrl: $actionUrl,
            reason: $reason,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject?->getKey(),
        ));
    }
}
