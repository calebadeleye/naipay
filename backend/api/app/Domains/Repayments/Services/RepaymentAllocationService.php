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
 * The default order's `instalment` bucket settles each schedule entry
 * completely — fee, then interest, then principal — before moving to the
 * next one, oldest due date first. Walking in due-date order already puts
 * every overdue entry ahead of every current one, so a payment that only
 * covers part of the schedule reads the way a collector actually explains
 * it: "this day is fully paid, that one is still open" — never a sliver of
 * interest credited against a dozen future days while none of them show any
 * principal paid. The older per-field buckets (`overdue_interest`,
 * `current_interest`, `overdue_principal`, `current_principal`) remain
 * implemented and selectable via configuration, since operational policy
 * here is configuration, not code — but they are no longer the shipped
 * default.
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
                'instalment' => $this->applyInstalmentBucket($entries, $remaining, $deltas),
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

        foreach ($entries as $entry) {
            if ($remaining->isZero()) {
                break;
            }

            if ($filter !== null && ! $filter($entry)) {
                continue;
            }

            $remaining = $this->takeFromEntry($entry, $remaining, $deltas, $field);
        }

        return $remaining;
    }

    /**
     * Settles each entry completely — fee, then interest, then principal —
     * before moving to the next one. `$entries` is already ordered
     * oldest-due-first, so this naturally clears every overdue instalment in
     * full before a future one receives anything, without needing a separate
     * overdue/current split.
     *
     * @param  Collection<int, LoanScheduleEntry>  $entries
     * @param  array<int, array{principal: Money, interest: Money, fee: Money}>  $deltas
     */
    private function applyInstalmentBucket(Collection $entries, Money $remaining, array &$deltas): Money
    {
        if ($remaining->isZero()) {
            return $remaining;
        }

        foreach ($entries as $entry) {
            if ($remaining->isZero()) {
                break;
            }

            foreach (['fee', 'interest', 'principal'] as $field) {
                if ($remaining->isZero()) {
                    break;
                }

                $remaining = $this->takeFromEntry($entry, $remaining, $deltas, $field);
            }
        }

        return $remaining;
    }

    /**
     * @param  array<int, array{principal: Money, interest: Money, fee: Money}>  $deltas
     */
    private function takeFromEntry(LoanScheduleEntry $entry, Money $remaining, array &$deltas, string $field): Money
    {
        $dueField = "{$field}_due";
        $paidField = "{$field}_paid";

        $alreadyAllocatedThisPlan = $deltas[$entry->id][$field] ?? Money::zero($remaining->currency());
        $outstanding = $entry->{$dueField}->minus($entry->{$paidField})->minus($alreadyAllocatedThisPlan);

        if (! $outstanding->isPositive()) {
            return $remaining;
        }

        $take = $remaining->cappedAt($outstanding);

        if (! $take->isPositive()) {
            return $remaining;
        }

        $deltas[$entry->id] ??= [
            'principal' => Money::zero($remaining->currency()),
            'interest' => Money::zero($remaining->currency()),
            'fee' => Money::zero($remaining->currency()),
        ];
        $deltas[$entry->id][$field] = $deltas[$entry->id][$field]->plus($take);

        return $remaining->minus($take);
    }
}
