<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Services;

use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Repayments\Data\AllocationLine;
use App\Domains\Repayments\Data\AllocationPlan;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out how a repayment amount is spread across a loan's outstanding
 * instalments.
 *
 * The order is never hard-coded: `naipay.allocation.default_order` names the
 * buckets, in the sequence an approved repayment is consumed. Instalments are
 * always walked oldest-due-first within a bucket, so two payments of the same
 * size against the same loan produce the same plan regardless of which
 * officer records which.
 *
 * The `penalty` bucket is a recognised no-op here: nothing in the loan
 * product configuration states how a late-payment penalty accrues (once per
 * instalment, per day, compounding), so charging one would be inventing a
 * figure rather than computing one. It stays reserved in the allocation order
 * for whichever future phase defines that policy explicitly.
 */
final class RepaymentAllocationService
{
    public function plan(Loan $loan, Money $amount, Carbon $paymentDate): AllocationPlan
    {
        $entries = $loan->scheduleEntries()->orderBy('installment_number')->get();

        $remaining = $amount;

        /** @var array<int, array{principal: Money, interest: Money, fee: Money}> $deltas */
        $deltas = [];

        $isOverdue = static fn (LoanScheduleEntry $entry): bool => $entry->due_date->lt($paymentDate);
        $isCurrent = static fn (LoanScheduleEntry $entry): bool => ! $isOverdue($entry);

        foreach (config('naipay.allocation.default_order', []) as $bucket) {
            $remaining = match ($bucket) {
                'fee' => $this->applyBucket($entries, $remaining, $deltas, 'fee', null),
                'overdue_interest' => $this->applyBucket($entries, $remaining, $deltas, 'interest', $isOverdue),
                'current_interest' => $this->applyBucket($entries, $remaining, $deltas, 'interest', $isCurrent),
                'overdue_principal' => $this->applyBucket($entries, $remaining, $deltas, 'principal', $isOverdue),
                'current_principal' => $this->applyBucket($entries, $remaining, $deltas, 'principal', $isCurrent),
                // 'penalty' and anything not recognised: left untouched.
                default => $remaining,
            };
        }

        $currency = $amount->currency();
        $zero = Money::zero($currency);

        $entryAllocations = [];
        $principalTotal = $zero;
        $interestTotal = $zero;
        $feeTotal = $zero;

        foreach ($deltas as $entryId => $delta) {
            $entryAllocations[] = new AllocationLine($entryId, $delta['principal'], $delta['interest'], $delta['fee']);
            $principalTotal = $principalTotal->plus($delta['principal']);
            $interestTotal = $interestTotal->plus($delta['interest']);
            $feeTotal = $feeTotal->plus($delta['fee']);
        }

        // Which bucket an unspent remainder lands in — not whether one is
        // allowed to exist at all. That policy (naipay.allocation.
        // allow_overpayment) belongs to RepaymentService, which refuses to
        // approve a repayment at all when a remainder is present and
        // overpayment is disabled, rather than silently parking it here.
        $surplusBucket = (string) config('naipay.allocation.surplus_bucket', 'excess');

        $excessTotal = $remaining->isPositive() && $surplusBucket === 'excess' ? $remaining : $zero;
        $unallocatedTotal = $remaining->isPositive() && $surplusBucket !== 'excess' ? $remaining : $zero;

        return new AllocationPlan(
            amount: $amount,
            entryAllocations: $entryAllocations,
            principalTotal: $principalTotal,
            interestTotal: $interestTotal,
            feeTotal: $feeTotal,
            excessTotal: $excessTotal,
            unallocatedTotal: $unallocatedTotal,
        );
    }

    /**
     * @param  Collection<int, LoanScheduleEntry>  $entries
     * @param  array<int, array{principal: Money, interest: Money, fee: Money}>  $deltas
     */
    private function applyBucket(
        Collection $entries,
        Money $remaining,
        array &$deltas,
        string $field,
        ?callable $filter,
    ): Money {
        if ($remaining->isZero()) {
            return $remaining;
        }

        $dueField = "{$field}_due";
        $paidField = "{$field}_paid";

        foreach ($entries as $entry) {
            if ($remaining->isZero()) {
                break;
            }

            if ($filter !== null && ! $filter($entry)) {
                continue;
            }

            $alreadyAllocatedThisPlan = $deltas[$entry->id][$field] ?? Money::zero($remaining->currency());
            $outstanding = $entry->{$dueField}->minus($entry->{$paidField})->minus($alreadyAllocatedThisPlan);

            if (! $outstanding->isPositive()) {
                continue;
            }

            $take = $remaining->cappedAt($outstanding);

            if (! $take->isPositive()) {
                continue;
            }

            $deltas[$entry->id] ??= [
                'principal' => Money::zero($remaining->currency()),
                'interest' => Money::zero($remaining->currency()),
                'fee' => Money::zero($remaining->currency()),
            ];
            $deltas[$entry->id][$field] = $deltas[$entry->id][$field]->plus($take);

            $remaining = $remaining->minus($take);
        }

        return $remaining;
    }
}
