<?php

declare(strict_types=1);

namespace App\Domains\LoanProducts\Services;

use App\Domains\LoanProducts\Data\Instalment;
use App\Domains\LoanProducts\Data\LoanSchedule;
use App\Domains\LoanProducts\Data\LoanTerms;
use App\Domains\LoanProducts\Enums\InterestMethod;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Support\Money\Money;
use App\Support\Money\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Computes loan interest and builds repayment schedules.
 *
 * Pure and deterministic: same terms in, same schedule out, every time. No
 * database, no clock, no configuration read at calculation time — everything
 * it needs arrives in LoanTerms. That is what makes a schedule previewable
 * before a loan exists and exhaustively testable without one.
 *
 * All arithmetic is integer kobo through Money. There is no float anywhere in
 * this file, and every division states its rounding.
 *
 * Remainders are handled by distributing them across the leading instalments
 * rather than dumping them on the last, so no single payment is visibly out of
 * step, and the schedule sums back to the loan exactly. LoanSchedule refuses to
 * be constructed if it does not.
 */
final class LoanCalculator
{
    public function __construct(
        private readonly BusinessDayCalendar $calendar,
    ) {}

    /**
     * Total interest over the life of the loan.
     */
    public function totalInterest(LoanTerms $terms): Money
    {
        return match ($terms->interestMethod) {
            // Charged once on the amount borrowed. A Naipay daily loan costs
            // 20% whether it runs twenty working days or forty.
            InterestMethod::Flat => $terms->principal->percentageOf($terms->interestRate),

            InterestMethod::SimpleInterest => $terms->principal
                ->percentageOf($terms->interestRate)
                ->multiplyBy((string) $terms->tenor),

            InterestMethod::DecliningBalance => $this->decliningBalanceInterest($terms),

            InterestMethod::ReducingBalance => $this->reducingBalanceInterest($terms),
        };
    }

    /**
     * Builds the full repayment schedule.
     */
    public function schedule(LoanTerms $terms, ?Money $fees = null): LoanSchedule
    {
        $fees ??= Money::zero($terms->principal->currency());

        $dueDates = $this->dueDates($terms);

        $instalments = match ($terms->interestMethod) {
            InterestMethod::Flat,
            InterestMethod::SimpleInterest => $this->evenInstalments($terms, $dueDates, $fees),

            InterestMethod::DecliningBalance => $this->decliningBalanceInstalments($terms, $dueDates, $fees),

            InterestMethod::ReducingBalance => $this->reducingBalanceInstalments($terms, $dueDates, $fees),
        };

        return new LoanSchedule(
            instalments: $instalments,
            principal: $terms->principal,
            totalInterest: Money::sum(array_map(
                static fn (Instalment $i): Money => $i->interestDue,
                $instalments,
            ), $terms->principal->currency()),
            totalFees: $fees,
        );
    }

    /**
     * The dates instalments fall due.
     *
     * For daily collection these are working days only. Merchants are not
     * expected to pay at the weekend, and an instalment landing on a Saturday
     * would be marked overdue on the Monday for a payment nobody asked for.
     *
     * @return array<int, Carbon>
     */
    public function dueDates(LoanTerms $terms): array
    {
        $dates = [];
        $cursor = $terms->disbursementDate->copy()->startOfDay();

        // An explicit first repayment date overrides the derived one, so an
        // officer can align collection with a merchant's trading cycle.
        if ($terms->firstRepaymentDate !== null) {
            $first = $terms->firstRepaymentDate->copy()->startOfDay();
        } else {
            $afterGrace = $cursor->copy()->addDays($terms->gracePeriodDays);
            $first = $this->advance($afterGrace, $terms->frequency, 1);
        }

        if ($terms->frequency->skipsWeekends()) {
            $first = $this->calendar->onOrAfter($first);
        }

        $dates[] = $first;

        for ($instalment = 2; $instalment <= $terms->tenor; $instalment++) {
            $previous = $dates[count($dates) - 1];
            $next = $this->advance($previous, $terms->frequency, 1);

            if ($terms->frequency->skipsWeekends()) {
                $next = $this->calendar->onOrAfter($next);
            }

            $dates[] = $next;
        }

        return $dates;
    }

    /**
     * Advances a date by one period of the given frequency.
     *
     * Daily advances by one working day, so Friday's next instalment is
     * Monday's rather than Saturday's.
     */
    private function advance(Carbon $from, RepaymentFrequency $frequency, int $periods): Carbon
    {
        return match ($frequency) {
            RepaymentFrequency::Daily => $this->calendar->addBusinessDays($from, $periods),
            RepaymentFrequency::Weekly => $from->copy()->addWeeks($periods),
            // addMonthsNoOverflow keeps a 31st-of-the-month loan from skipping
            // February entirely.
            RepaymentFrequency::Monthly => $from->copy()->addMonthsNoOverflow($periods),
        };
    }

    /**
     * Equal instalments: principal and interest each split evenly, with
     * remainder kobo distributed across the leading instalments.
     *
     * Used by Flat and Simple Interest, where the total is known upfront and
     * does not depend on the outstanding balance.
     *
     * @param  array<int, Carbon>  $dueDates
     * @return array<int, Instalment>
     */
    private function evenInstalments(LoanTerms $terms, array $dueDates, Money $fees): array
    {
        $count = count($dueDates);

        $principalParts = $terms->principal->allocateEvenly($count);
        $interestParts = $this->totalInterest($terms)->allocateEvenly($count);

        // Fees ride on the first instalment rather than being spread: a
        // processing fee is incurred once, at the start, and spreading it would
        // imply it is being earned over the term.
        $feeParts = array_fill(0, $count, Money::zero($terms->principal->currency()));
        $feeParts[0] = $fees;

        $instalments = [];
        $outstanding = $terms->principal;

        foreach ($dueDates as $index => $dueDate) {
            $instalments[] = new Instalment(
                number: $index + 1,
                dueDate: $dueDate,
                openingPrincipal: $outstanding,
                principalDue: $principalParts[$index],
                interestDue: $interestParts[$index],
                feeDue: $feeParts[$index],
            );

            $outstanding = $outstanding->minus($principalParts[$index]);
        }

        return $instalments;
    }

    /**
     * Declining balance: principal repaid in equal parts, interest charged each
     * period on what is still outstanding, so instalments shrink.
     *
     * @param  array<int, Carbon>  $dueDates
     * @return array<int, Instalment>
     */
    private function decliningBalanceInstalments(LoanTerms $terms, array $dueDates, Money $fees): array
    {
        $count = count($dueDates);
        $principalParts = $terms->principal->allocateEvenly($count);

        $instalments = [];
        $outstanding = $terms->principal;

        foreach ($dueDates as $index => $dueDate) {
            // Interest on the balance at the start of the period, before this
            // instalment's principal is applied.
            $interest = $outstanding->percentageOf($terms->interestRate);

            $instalments[] = new Instalment(
                number: $index + 1,
                dueDate: $dueDate,
                openingPrincipal: $outstanding,
                principalDue: $principalParts[$index],
                interestDue: $interest,
                feeDue: $index === 0 ? $fees : Money::zero($terms->principal->currency()),
            );

            $outstanding = $outstanding->minus($principalParts[$index]);
        }

        return $instalments;
    }

    private function decliningBalanceInterest(LoanTerms $terms): Money
    {
        $total = Money::zero($terms->principal->currency());
        $principalParts = $terms->principal->allocateEvenly($terms->tenor);
        $outstanding = $terms->principal;

        for ($period = 0; $period < $terms->tenor; $period++) {
            $total = $total->plus($outstanding->percentageOf($terms->interestRate));
            $outstanding = $outstanding->minus($principalParts[$period]);
        }

        return $total;
    }

    /**
     * Reducing balance with level instalments — the annuity, or EMI, method.
     *
     * The instalment is the only figure here that cannot be derived with
     * integer arithmetic alone, because it needs (1 + r)^n. It is computed once
     * with bcmath at high precision and immediately rounded to whole kobo;
     * everything after that is exact integer work, and the final instalment
     * absorbs the accumulated rounding so the schedule closes to zero.
     *
     * @param  array<int, Carbon>  $dueDates
     * @return array<int, Instalment>
     */
    private function reducingBalanceInstalments(LoanTerms $terms, array $dueDates, Money $fees): array
    {
        $count = count($dueDates);
        $payment = $this->annuityPayment($terms, $count);

        $instalments = [];
        $outstanding = $terms->principal;

        foreach ($dueDates as $index => $dueDate) {
            $interest = $outstanding->percentageOf($terms->interestRate);

            $isFinal = $index === $count - 1;

            // The last instalment clears whatever principal remains, absorbing
            // the rounding carried through the schedule. Anything else would
            // leave a few kobo outstanding on a fully repaid loan.
            $principalDue = $isFinal
                ? $outstanding
                : $payment->minus($interest)->cappedAt($outstanding);

            // A payment smaller than the interest accruing would never repay
            // the loan; clamping keeps the schedule finite and the error
            // visible as an unusually large final instalment.
            if ($principalDue->isNegative()) {
                $principalDue = Money::zero($terms->principal->currency());
            }

            $instalments[] = new Instalment(
                number: $index + 1,
                dueDate: $dueDate,
                openingPrincipal: $outstanding,
                principalDue: $principalDue,
                interestDue: $interest,
                feeDue: $index === 0 ? $fees : Money::zero($terms->principal->currency()),
            );

            $outstanding = $outstanding->minus($principalDue);
        }

        return $instalments;
    }

    private function reducingBalanceInterest(LoanTerms $terms): Money
    {
        $schedule = $this->reducingBalanceInstalments(
            $terms,
            $this->dueDates($terms),
            Money::zero($terms->principal->currency()),
        );

        return Money::sum(array_map(
            static fn (Instalment $i): Money => $i->interestDue,
            $schedule,
        ), $terms->principal->currency());
    }

    /**
     * The level payment for an annuity: P × r / (1 − (1 + r)^−n).
     *
     * Computed at 12 decimal places and rounded to kobo once.
     */
    private function annuityPayment(LoanTerms $terms, int $periods): Money
    {
        $scale = 12;
        $rate = bcdiv($terms->interestRate, '100', $scale);

        // A zero rate is just the principal split evenly.
        if (bccomp($rate, '0', $scale) === 0) {
            return $terms->principal->divideBy($periods, RoundingMode::Up);
        }

        // (1 + r)^n
        $growth = bcpow(bcadd('1', $rate, $scale), (string) $periods, $scale);

        // P × r × growth / (growth − 1)
        $numerator = bcmul($terms->principal->toDecimalString(), bcmul($rate, $growth, $scale), $scale);
        $denominator = bcsub($growth, '1', $scale);

        $payment = bcdiv($numerator, $denominator, 2);

        return Money::fromDecimal($payment, $terms->principal->currency());
    }
}
