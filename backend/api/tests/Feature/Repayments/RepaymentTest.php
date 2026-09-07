<?php

declare(strict_types=1);

namespace Tests\Feature\Repayments;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\Loans\Enums\LoanScheduleEntryStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Domains\Repayments\Services\RepaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manual repayment recording.
 *
 * Three officers, three functions: recording is an officer reporting what
 * the bank shows, verification is a second officer checking that against the
 * evidence, and only approval — maker-checked against whoever verified —
 * actually allocates the amount and posts the ledger. What matters here:
 * allocation always follows naipay.allocation.default_order rather than a
 * hard-coded rule, every approved repayment balances in the ledger, and a
 * reversal undoes precisely what approval did, using the per-instalment
 * breakdown recorded at the time rather than a reconstruction from totals.
 */
final class RepaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    // --- Recording ---------------------------------------------------------------

    #[Test]
    public function recording_a_repayment_against_a_disbursed_loan_succeeds(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsRecord]);
        $loan = $this->loanWithThreeInstalments();
        $account = $this->collectionAccount();

        $response = $this->postJson('/api/v1/admin/repayments', [
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'amount' => '15000.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'bank_reference' => 'TRX-000001',
        ]);

        $response->assertCreated();
        $this->assertSame(RepaymentStatus::Recorded->value, $response->json('data.status'));
        $this->assertSame($loan->merchant_id, Repayment::query()->findOrFail($response->json('data.id'))->merchant_id);
    }

    #[Test]
    public function a_repayment_cannot_be_recorded_against_a_loan_that_is_not_disbursed(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsRecord]);
        $loan = Loan::factory()->pendingDisbursement()->create();
        $account = $this->collectionAccount();

        $response = $this->postJson('/api/v1/admin/repayments', [
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'amount' => '1000.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function recording_requires_the_repayments_record_permission(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsView]);
        $loan = $this->loanWithThreeInstalments();

        $this->postJson('/api/v1/admin/repayments', [
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $this->collectionAccount()->id,
            'amount' => '1000.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
        ])->assertForbidden();
    }

    #[Test]
    public function an_exact_bank_reference_match_is_blocked_outright(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsRecord]);
        $loan = $this->loanWithThreeInstalments();
        $account = $this->collectionAccount();

        Repayment::factory()->create([
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'bank_reference' => 'TRX-DUPLICATE',
            'payment_date' => now()->toDateString(),
        ]);

        $response = $this->postJson('/api/v1/admin/repayments', [
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'bank_reference' => 'TRX-DUPLICATE',
            // Even a caller claiming to have confirmed it cannot override a
            // block-level match — only a warn-level one.
            'confirm_duplicate' => true,
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Repayment::query()->where('bank_reference', 'TRX-DUPLICATE')->count());
    }

    #[Test]
    public function a_probable_duplicate_is_refused_unless_confirmed(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsRecord]);
        $loan = $this->loanWithThreeInstalments();
        $account = $this->collectionAccount();

        Repayment::factory()->create([
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'payment_date' => now()->toDateString(),
            'sender_account_name' => 'Adaeze Okafor',
            'bank_reference' => 'TRX-FIRST',
        ]);

        $payload = [
            'loan_id' => $loan->id,
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'payment_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'sender_account_name' => 'Adaeze Okafor',
            'bank_reference' => 'TRX-SECOND',
        ];

        $this->postJson('/api/v1/admin/repayments', $payload)->assertStatus(422);

        $payload['confirm_duplicate'] = true;
        $this->postJson('/api/v1/admin/repayments', $payload)->assertCreated();
    }

    // --- Verification and rejection -----------------------------------------------

    #[Test]
    public function a_finance_officer_can_verify_a_recorded_repayment(): void
    {
        $this->actingAsRole(Role::FinanceOfficer);
        $repayment = Repayment::factory()->create();

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/verify", [
            'notes' => 'Matches the bank statement for this period.',
        ]);

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Verified->value, $response->json('data.status'));
    }

    #[Test]
    public function a_verified_repayment_can_be_rejected(): void
    {
        $this->actingAsRole(Role::FinanceOfficer);
        $repayment = Repayment::factory()->verified()->create();

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reject", [
            'reason' => 'No matching transaction on the bank statement.',
        ]);

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Rejected->value, $response->json('data.status'));
    }

    #[Test]
    public function an_approved_repayment_cannot_be_rejected(): void
    {
        $this->actingAsRole(Role::FinanceOfficer);
        $repayment = Repayment::factory()->create(['status' => RepaymentStatus::Approved]);

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reject", [
            'reason' => 'Attempting to reject something already approved.',
        ]);

        $response->assertStatus(422);
    }

    // --- Approval and allocation ----------------------------------------------------

    #[Test]
    public function approving_settles_each_instalment_completely_oldest_due_date_first(): void
    {
        $loan = $this->loanWithThreeInstalments();
        $verifier = Staff::factory()->create();

        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '15000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve");

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Approved->value, $response->json('data.status'));
        $this->assertSame('11000.00', $response->json('data.allocation.principal.amount'));
        $this->assertSame('4000.00', $response->json('data.allocation.interest.amount'));
        $this->assertSame('0.00', $response->json('data.allocation.excess.amount'));

        $entries = $loan->scheduleEntries()->orderBy('installment_number')->get();

        // Entry 1 (oldest overdue): fully cleared — interest (2000) and all
        // 10000 of its principal — before entry 2 receives anything.
        $this->assertSame('10000.00', $entries[0]->principal_paid->toDecimalString());
        $this->assertSame('2000.00', $entries[0]->interest_paid->toDecimalString());
        $this->assertSame(LoanScheduleEntryStatus::Paid, $entries[0]->fresh()->status);

        // Entry 2 (also overdue): its interest is cleared in full, but only
        // 1000 of its 10000 principal is left once entry 1 is settled — the
        // repayment ran out here.
        $this->assertSame('1000.00', $entries[1]->principal_paid->toDecimalString());
        $this->assertSame('2000.00', $entries[1]->interest_paid->toDecimalString());
        $this->assertSame(LoanScheduleEntryStatus::PartiallyPaid, $entries[1]->fresh()->status);

        // Entry 3 (current, not overdue): untouched — nothing was left once
        // entries 1 and 2 were addressed in due-date order.
        $this->assertSame('0.00', $entries[2]->principal_paid->toDecimalString());
        $this->assertSame('0.00', $entries[2]->interest_paid->toDecimalString());
        $this->assertSame(LoanScheduleEntryStatus::Pending, $entries[2]->fresh()->status);

        $loan->refresh();
        $this->assertSame('19000.00', $loan->outstanding_principal->toDecimalString());
        $this->assertSame('2000.00', $loan->outstanding_interest->toDecimalString());

        $transaction = JournalTransaction::query()->findOrFail($repayment->fresh()->repayment_journal_transaction_id);
        $lines = $transaction->entries()->get();

        $this->assertSame('15000.00', $lines->firstWhere('debit_amount.>', 0)->debit_amount->toDecimalString());

        $receivableAccount = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $incomeAccount = LedgerAccount::query()->where('code', StandardAccounts::INTEREST_INCOME)->firstOrFail();

        $principalLine = $lines->firstWhere('ledger_account_id', $receivableAccount->id);
        $interestLine = $lines->firstWhere('ledger_account_id', $incomeAccount->id);

        $this->assertSame('11000.00', $principalLine->credit_amount->toDecimalString());
        $this->assertSame('4000.00', $interestLine->credit_amount->toDecimalString());
    }

    #[Test]
    public function an_overpayment_covering_multiple_future_instalments_marks_those_future_days_paid(): void
    {
        // A flat daily schedule: 6000/day due, split 5800 principal / 200
        // interest, three future days, none overdue yet.
        $loan = Loan::factory()->disbursed()->create([
            'principal_amount' => '17400.00',
            'outstanding_principal' => '17400.00',
            'outstanding_interest' => '600.00',
            'outstanding_fees' => '0.00',
        ]);

        foreach ([1, 2, 3] as $number) {
            LoanScheduleEntry::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => now()->addDays($number)->toDateString(),
                'opening_principal' => '17400.00',
                'principal_due' => '5800.00',
                'interest_due' => '200.00',
                'fee_due' => '0.00',
            ]);
        }

        $verifier = Staff::factory()->create();

        // Exactly two days' worth, paid ahead of either day being due.
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '12000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $this->actingAsRole(Role::FinanceManager);

        $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve")->assertOk();

        $entries = $loan->scheduleEntries()->orderBy('installment_number')->get();

        $this->assertSame(LoanScheduleEntryStatus::Paid, $entries[0]->fresh()->status);
        $this->assertSame(LoanScheduleEntryStatus::Paid, $entries[1]->fresh()->status);
        $this->assertSame(LoanScheduleEntryStatus::Pending, $entries[2]->fresh()->status);
    }

    #[Test]
    public function an_overpayment_between_one_and_two_days_pays_one_day_fully_and_the_next_partially(): void
    {
        $loan = Loan::factory()->disbursed()->create([
            'principal_amount' => '17400.00',
            'outstanding_principal' => '17400.00',
            'outstanding_interest' => '600.00',
            'outstanding_fees' => '0.00',
        ]);

        foreach ([1, 2, 3] as $number) {
            LoanScheduleEntry::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'due_date' => now()->addDays($number)->toDateString(),
                'opening_principal' => '17400.00',
                'principal_due' => '5800.00',
                'interest_due' => '200.00',
                'fee_due' => '0.00',
            ]);
        }

        $verifier = Staff::factory()->create();

        // Between one day (6000) and two days (12000).
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '11500.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $this->actingAsRole(Role::FinanceManager);

        $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve")->assertOk();

        $entries = $loan->scheduleEntries()->orderBy('installment_number')->get();

        $this->assertSame(LoanScheduleEntryStatus::Paid, $entries[0]->fresh()->status);
        $this->assertSame(LoanScheduleEntryStatus::PartiallyPaid, $entries[1]->fresh()->status);
        $this->assertSame('5500.00', $entries[1]->fresh()->principal_paid->plus($entries[1]->fresh()->interest_paid)->toDecimalString());
        $this->assertSame(LoanScheduleEntryStatus::Pending, $entries[2]->fresh()->status);
    }

    #[Test]
    public function an_amount_beyond_the_outstanding_balance_is_credited_to_the_merchants_own_account(): void
    {
        $loan = Loan::factory()->disbursed()->create([
            'outstanding_principal' => '5000.00',
            'outstanding_interest' => '1000.00',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'opening_principal' => '5000.00',
            'principal_due' => '5000.00',
            'interest_due' => '1000.00',
            'fee_due' => '0.00',
        ]);

        MerchantAccount::factory()->create(['merchant_id' => $loan->merchant_id]);

        $verifier = Staff::factory()->create();
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '7000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve");

        $response->assertOk();
        $this->assertSame('1000.00', $response->json('data.allocation.excess.amount'));

        $account = MerchantAccount::query()->where('merchant_id', $loan->merchant_id)->firstOrFail();
        $this->assertSame('1000.00', $account->available_balance->toDecimalString());
        $this->assertSame('1000.00', $account->ledger_balance->toDecimalString());

        $liabilityAccount = LedgerAccount::query()->where('code', StandardAccounts::MERCHANT_SAVINGS_LIABILITY)->firstOrFail();
        $this->assertSame('1000.00', $liabilityAccount->currentBalance()->toDecimalString());
    }

    #[Test]
    public function overpayment_can_be_refused_entirely_instead_of_parked(): void
    {
        config(['naipay.allocation.allow_overpayment' => false]);

        $loan = Loan::factory()->disbursed()->create([
            'outstanding_principal' => '5000.00',
            'outstanding_interest' => '0.00',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'opening_principal' => '5000.00',
            'principal_due' => '5000.00',
            'interest_due' => '0.00',
            'fee_due' => '0.00',
        ]);

        $verifier = Staff::factory()->create();
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '6000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve");

        $response->assertStatus(422);
        $this->assertSame(RepaymentStatus::Verified, $repayment->fresh()->status);
    }

    #[Test]
    public function the_officer_who_verified_a_repayment_can_now_approve_it(): void
    {
        $verifier = $this->actingAsRole(Role::FinanceManager);
        $loan = $this->loanWithThreeInstalments();

        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '5000.00',
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        // Self-approval is no longer restricted — see
        // docs/roles-and-permissions.md.
        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve");

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Approved, $repayment->fresh()->status);
    }

    #[Test]
    public function a_recorded_but_unverified_repayment_cannot_be_approved(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $repayment = Repayment::factory()->create();

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/approve");

        $response->assertStatus(422);
    }

    // --- Reversal --------------------------------------------------------------------

    #[Test]
    public function reversing_an_approved_repayment_undoes_every_balance_it_touched(): void
    {
        $loan = $this->loanWithThreeInstalments();
        $verifier = Staff::factory()->create();

        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '15000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $approver = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->approve($repayment->fresh(), $approver);

        $reverser = $this->actingAsRole(Role::FinanceManager);
        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reverse", [
            'reason' => 'Recorded against the wrong loan in error.',
        ]);

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Reversed->value, $response->json('data.status'));

        $loan->refresh();
        $this->assertSame('30000.00', $loan->outstanding_principal->toDecimalString());
        $this->assertSame('6000.00', $loan->outstanding_interest->toDecimalString());

        foreach ($loan->scheduleEntries as $entry) {
            $this->assertTrue($entry->principal_paid->isZero());
            $this->assertTrue($entry->interest_paid->isZero());
            $this->assertSame(LoanScheduleEntryStatus::Pending, $entry->status);
        }

        $original = JournalTransaction::query()->findOrFail($repayment->fresh()->repayment_journal_transaction_id);
        $this->assertTrue($original->fresh()->isReversed());

        $reversal = JournalTransaction::query()->findOrFail($repayment->fresh()->reversal_journal_transaction_id);
        $this->assertTrue($reversal->isReversal());

        $receivableAccount = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $this->assertTrue($receivableAccount->currentBalance()->isZero());
    }

    #[Test]
    public function reversing_a_repayment_with_excess_debits_back_the_merchants_account(): void
    {
        $loan = Loan::factory()->disbursed()->create([
            'outstanding_principal' => '5000.00',
            'outstanding_interest' => '0.00',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'opening_principal' => '5000.00',
            'principal_due' => '5000.00',
            'interest_due' => '0.00',
            'fee_due' => '0.00',
        ]);
        MerchantAccount::factory()->create(['merchant_id' => $loan->merchant_id]);

        $verifier = Staff::factory()->create();
        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '6000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $approver = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->approve($repayment->fresh(), $approver);

        $this->actingAsRole(Role::FinanceManager);
        $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reverse", [
            'reason' => 'Duplicate entry for the same transfer.',
        ])->assertOk();

        $account = MerchantAccount::query()->where('merchant_id', $loan->merchant_id)->firstOrFail();
        $this->assertTrue($account->available_balance->isZero());
        $this->assertTrue($account->ledger_balance->isZero());
    }

    #[Test]
    public function the_officer_who_approved_a_repayment_can_now_reverse_it(): void
    {
        $loan = $this->loanWithThreeInstalments();
        $verifier = Staff::factory()->create();

        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => '15000.00',
            'payment_date' => now()->toDateString(),
            'verified_by' => $verifier->id,
            'status' => RepaymentStatus::Verified,
        ]);

        $approver = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->approve($repayment->fresh(), $approver);

        $this->actingAsRole(Role::FinanceManager);
        Sanctum::actingAs($approver->fresh(), $approver->fresh()->permissionNames()->all(), 'staff');

        // Self-approval is no longer restricted — see
        // docs/roles-and-permissions.md.
        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reverse", [
            'reason' => 'Recorded against the wrong loan in error.',
        ]);

        $response->assertOk();
        $this->assertSame(RepaymentStatus::Reversed->value, $response->json('data.status'));
    }

    #[Test]
    public function a_repayment_that_has_not_been_approved_cannot_be_reversed(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $repayment = Repayment::factory()->verified()->create();

        $response = $this->postJson("/api/v1/admin/repayments/{$repayment->id}/reverse", [
            'reason' => 'Attempting to reverse something never approved.',
        ]);

        $response->assertStatus(422);
    }

    // --- Reading and deletion --------------------------------------------------------

    #[Test]
    public function viewing_the_repayment_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/repayments')->assertUnauthorized();
    }

    #[Test]
    public function there_is_no_delete_endpoint_for_a_repayment(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $repayment = Repayment::factory()->create();

        $this->deleteJson("/api/v1/admin/repayments/{$repayment->id}")->assertStatus(405);
    }

    /**
     * A loan with three daily instalments: two already overdue as of today,
     * one still current — deliberately built by hand rather than through
     * LoanDisbursementService, so the allocation math can be verified against
     * numbers chosen for the test rather than whatever the calculator
     * produces for an arbitrary product.
     */
    private function loanWithThreeInstalments(): Loan
    {
        $loan = Loan::factory()->disbursed()->create([
            'principal_amount' => '30000.00',
            'outstanding_principal' => '30000.00',
            'outstanding_interest' => '6000.00',
            'outstanding_fees' => '0.00',
        ]);

        foreach ([
            ['number' => 1, 'due' => now()->subDays(3)->toDateString()],
            ['number' => 2, 'due' => now()->subDays(2)->toDateString()],
            ['number' => 3, 'due' => now()->addDay()->toDateString()],
        ] as $row) {
            LoanScheduleEntry::factory()->create([
                'loan_id' => $loan->id,
                'installment_number' => $row['number'],
                'due_date' => $row['due'],
                'opening_principal' => '30000.00',
                'principal_due' => '10000.00',
                'interest_due' => '2000.00',
                'fee_due' => '0.00',
            ]);
        }

        return $loan->fresh();
    }

    private function collectionAccount(): BankAccount
    {
        return BankAccount::factory()->approved()->create([
            'purposes' => [BankAccountPurpose::LoanRepaymentCollection->value],
        ]);
    }
}
