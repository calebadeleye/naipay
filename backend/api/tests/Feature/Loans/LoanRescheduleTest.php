<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Domains\Identity\Enums\Role;
use App\Domains\LoanProducts\Enums\RepaymentFrequency;
use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rescheduling a loan's repayment dates — a calendar-only shift for a public
 * holiday or at a merchant's request. Amounts, interest and the loan's totals
 * never move; only due dates do.
 */
final class LoanRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, so plain day arithmetic in the weekly cases stays clear of
        // the weekend-rolling that only applies to daily loans.
        Carbon::setTestNow('2026-09-07 09:00:00');
    }

    #[Test]
    public function every_unpaid_instalment_shifts_by_the_same_offset(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Pending],
            [2, '2026-09-21', LoanScheduleEntryStatus::Pending],
            [3, '2026-09-28', LoanScheduleEntryStatus::Pending],
        ]);

        // Next instalment moves out three days; the rest follow.
        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-17',
            'reason' => 'Merchant requested a later collection day.',
        ]);

        $response->assertOk();

        $dueDates = LoanScheduleEntry::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->pluck('due_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame(['2026-09-17', '2026-09-24', '2026-10-01'], $dueDates);

        // Amounts are untouched.
        $entry = LoanScheduleEntry::query()->where('loan_id', $loan->id)->where('installment_number', 1)->firstOrFail();
        $this->assertSame('7500.00', $entry->principal_due->toDecimalString());
        $this->assertSame('1500.00', $entry->interest_due->toDecimalString());

        // Loan dates track the schedule; totals do not move.
        $loan->refresh();
        $this->assertSame('2026-09-17', $loan->first_repayment_date->toDateString());
        $this->assertSame('2026-10-01', $loan->maturity_date->toDateString());
    }

    #[Test]
    public function settled_instalments_are_left_where_they_fell(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Paid],
            [2, '2026-09-21', LoanScheduleEntryStatus::Pending],
            [3, '2026-09-28', LoanScheduleEntryStatus::Pending],
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-28',
            'reason' => 'Public holiday on the original collection day.',
        ])->assertOk();

        $dueDates = LoanScheduleEntry::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->pluck('due_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        // Paid instalment untouched; the two unpaid ones shift a week out.
        $this->assertSame(['2026-09-14', '2026-09-28', '2026-10-05'], $dueDates);

        $loan->refresh();
        // Instalment one did not move, so the first repayment date holds.
        $this->assertSame('2026-09-14', $loan->first_repayment_date->toDateString());
        $this->assertSame('2026-10-05', $loan->maturity_date->toDateString());
    }

    #[Test]
    public function a_shift_that_lands_on_a_weekend_rolls_forward_for_a_daily_loan(): void
    {
        $this->actingAsRole(Role::CreditManager);

        // Daily loan, instalments on consecutive working days.
        $loan = $this->loanWithSchedule(RepaymentFrequency::Daily, [
            [1, '2026-09-08', LoanScheduleEntryStatus::Pending], // Tue
            [2, '2026-09-09', LoanScheduleEntryStatus::Pending], // Wed
        ]);

        // +3 days: instalment 1 → Fri 11th, instalment 2 → Sat 12th, which must
        // roll to Mon 14th.
        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-11',
            'reason' => 'Merchant away for a family event this week.',
        ])->assertOk();

        $dueDates = LoanScheduleEntry::query()
            ->where('loan_id', $loan->id)
            ->orderBy('installment_number')
            ->pluck('due_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $this->assertSame(['2026-09-11', '2026-09-14'], $dueDates);
    }

    #[Test]
    public function a_loan_that_is_not_disbursed_cannot_be_rescheduled(): void
    {
        $this->actingAsRole(Role::CreditManager);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-30',
            'reason' => 'Nothing to reschedule before disbursement.',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_loan_with_no_outstanding_instalments_cannot_be_rescheduled(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Paid],
            [2, '2026-09-21', LoanScheduleEntryStatus::Paid],
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-30',
            'reason' => 'Every instalment on this loan is already settled.',
        ])->assertStatus(422);
    }

    #[Test]
    public function the_reschedule_requires_a_reason(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Pending],
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-21',
            'reason' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function the_new_due_date_cannot_be_in_the_past(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Pending],
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-01',
            'reason' => 'Trying to pull the schedule into the past.',
        ])->assertStatus(422)->assertJsonValidationErrors(['next_due_date']);
    }

    #[Test]
    public function rescheduling_needs_the_restructure_permission(): void
    {
        // Finance can disburse and write off, but not restructure.
        $this->actingAsRole(Role::FinanceManager);

        $loan = $this->loanWithSchedule(RepaymentFrequency::Weekly, [
            [1, '2026-09-14', LoanScheduleEntryStatus::Pending],
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/reschedule", [
            'next_due_date' => '2026-09-21',
            'reason' => 'Finance manager should not be able to do this.',
        ])->assertForbidden();
    }

    /**
     * @param  array<int, array{int, string, LoanScheduleEntryStatus}>  $entries
     *                                                                            Each row: [installment number, due date, status].
     */
    private function loanWithSchedule(RepaymentFrequency $frequency, array $entries): Loan
    {
        $loan = Loan::factory()->disbursed()->create([
            'repayment_frequency' => $frequency,
            'first_repayment_date' => $entries[0][1],
            'maturity_date' => $entries[array_key_last($entries)][1],
        ]);

        foreach ($entries as [$number, $dueDate, $status]) {
            $paid = $status === LoanScheduleEntryStatus::Paid;

            LoanScheduleEntry::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => $dueDate,
                'principal_due' => '7500.00',
                'interest_due' => '1500.00',
                'fee_due' => '0.00',
                'principal_paid' => $paid ? '7500.00' : '0.00',
                'interest_paid' => $paid ? '1500.00' : '0.00',
                'fee_paid' => '0.00',
                'status' => $status,
            ]);
        }

        return $loan->fresh();
    }
}
