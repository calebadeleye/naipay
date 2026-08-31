<?php

declare(strict_types=1);

namespace App\Domains\Loans\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanProducts\Services\BusinessDayCalendar;
use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pushes a disbursed loan's repayment schedule out to new dates.
 *
 * The narrow, deliberate scope: a public holiday lands on a due date, or a
 * merchant asks for their next payment to move. Only the dates change — every
 * instalment keeps its exact principal, interest and fee, the loan's totals
 * and outstanding balances are untouched, and no ledger entry is involved.
 * This is not a restructure of the terms; it is a calendar shift.
 *
 * All still-owed instalments move by the same offset — the gap between the
 * first unpaid instalment's current due date and the new date the operator
 * picks — so the rhythm of collection is preserved, just later (or earlier).
 * Instalments already settled are left exactly as they fell.
 */
final class LoanRescheduleService
{
    private const MODULE = 'loans';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BusinessDayCalendar $calendar,
    ) {}

    /**
     * @param  Carbon  $newNextDueDate  The date the earliest unpaid instalment
     *                                  should now fall due.
     */
    public function reschedule(Loan $loan, Carbon $newNextDueDate, string $reason, Staff $actor): Loan
    {
        if ($loan->status !== LoanStatus::Disbursed) {
            throw new DomainException(
                "A loan that is {$loan->status->label()} has no active schedule to reschedule.",
            );
        }

        /** @var Collection<int, LoanScheduleEntry> $entries */
        $entries = $loan->scheduleEntries()->orderBy('installment_number')->get();

        $unpaid = $entries->filter(
            static fn (LoanScheduleEntry $entry): bool => $entry->status !== LoanScheduleEntryStatus::Paid,
        )->values();

        if ($unpaid->isEmpty()) {
            throw new DomainException('This loan has no outstanding instalments to reschedule.');
        }

        /** @var LoanScheduleEntry $firstUnpaid */
        $firstUnpaid = $unpaid->first();

        $newNextDueDate = $newNextDueDate->copy()->startOfDay();

        if ($newNextDueDate->isBefore(Carbon::today())) {
            throw new DomainException('The new due date cannot be in the past.');
        }

        // The last settled instalment fixes the earliest the schedule may
        // resume: collection can never move behind money already taken.
        $lastPaid = $entries
            ->filter(static fn (LoanScheduleEntry $e): bool => $e->status === LoanScheduleEntryStatus::Paid)
            ->last();

        if ($lastPaid !== null && $newNextDueDate->lessThanOrEqualTo($lastPaid->due_date)) {
            throw new DomainException(
                'The new due date must be after the last settled instalment on '
                .$lastPaid->due_date->toFormattedDateString().'.',
            );
        }

        // Both operands are start-of-day, so the difference is a whole number
        // of days; the sign tells us whether the schedule moves out or in.
        $offsetDays = (int) $firstUnpaid->due_date->copy()->startOfDay()->diffInDays($newNextDueDate, false);

        if ($offsetDays === 0) {
            throw new DomainException('The new due date is the same as the current one — nothing to reschedule.');
        }

        $skipsWeekends = $loan->repayment_frequency->skipsWeekends();

        return DB::transaction(function () use ($loan, $unpaid, $offsetDays, $skipsWeekends, $reason, $actor): Loan {
            $before = $loan->getAttributes();

            foreach ($unpaid as $entry) {
                $shifted = $entry->due_date->copy()->startOfDay()->addDays($offsetDays);

                if ($skipsWeekends) {
                    $shifted = $this->calendar->onOrAfter($shifted);
                }

                $entry->forceFill(['due_date' => $shifted])->save();
            }

            /** @var LoanScheduleEntry $lastEntry */
            $lastEntry = $loan->scheduleEntries()->orderBy('installment_number')->get()->last();

            $loanChanges = ['maturity_date' => $lastEntry->due_date];

            // The first repayment date only moves if instalment one itself was
            // still outstanding and therefore shifted.
            if ($unpaid->firstWhere('installment_number', 1) !== null) {
                $loanChanges['first_repayment_date'] = $unpaid->firstWhere('installment_number', 1)->due_date;
            }

            $loan->forceFill($loanChanges)->save();

            $this->audit->recordChange('loan.rescheduled', self::MODULE, $loan, $before, reason: $reason, actor: $actor);

            return $loan->fresh(['scheduleEntries', 'merchant.account', 'business', 'loanProduct']);
        });
    }
}
