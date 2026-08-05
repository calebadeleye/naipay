<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Models\Loan;
use App\Domains\Reconciliation\Enums\BankReconciliationStatus;
use App\Domains\Reconciliation\Enums\BankStatementLineStatus;
use App\Domains\Reconciliation\Models\BankReconciliation;
use App\Domains\Reconciliation\Models\BankStatementLine;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manual bank reconciliation.
 *
 * There is no bank feed in this release, so every line is an officer's own
 * transcription of the statement. What matters here: a line only matches an
 * internal record that genuinely belongs to the same bank account and the
 * same amount, submission is refused while anything remains unresolved or
 * the statement's own arithmetic does not add up, and approval is
 * maker-checked against whoever did the matching.
 */
final class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // --- Opening and adding lines ------------------------------------------------

    #[Test]
    public function opening_a_reconciliation_succeeds(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();

        $response = $this->postJson('/api/v1/admin/reconciliations', [
            'bank_account_id' => $account->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'statement_opening_balance' => '100000.00',
            'statement_closing_balance' => '150000.00',
        ]);

        $response->assertCreated();
        $this->assertSame(BankReconciliationStatus::InProgress->value, $response->json('data.status'));
    }

    #[Test]
    public function opening_a_reconciliation_requires_the_match_permission(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationView]);
        $account = $this->collectionAccount();

        $this->postJson('/api/v1/admin/reconciliations', [
            'bank_account_id' => $account->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'statement_opening_balance' => '0.00',
            'statement_closing_balance' => '0.00',
        ])->assertForbidden();
    }

    #[Test]
    public function a_line_can_be_added_while_in_progress(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->create();

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/lines", [
            'statement_date' => now()->toDateString(),
            'description' => 'Transfer received',
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $response->assertCreated();
        $this->assertSame(BankStatementLineStatus::Unmatched->value, $response->json('data.status'));
    }

    #[Test]
    public function a_line_cannot_be_added_once_submitted(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->pendingApproval()->create();

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/lines", [
            'statement_date' => now()->toDateString(),
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $response->assertStatus(422);
    }

    // --- Matching ----------------------------------------------------------------

    #[Test]
    public function a_credit_line_can_be_matched_to_an_approved_repayment_on_the_same_account(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '7500.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '7500.00',
            'direction' => 'credit',
        ]);

        $response = $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        );

        $response->assertOk();
        $this->assertSame(BankStatementLineStatus::Matched->value, $response->json('data.status'));
        $this->assertSame('repayment', $response->json('data.matched_to.type'));
        $this->assertSame($repayment->repayment_reference, $response->json('data.matched_to.reference'));
    }

    #[Test]
    public function a_debit_line_can_be_matched_to_a_disbursed_loan_on_the_same_account(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $loan = Loan::factory()->disbursed()->create([
            'disbursement_bank_account_id' => $account->id,
            'principal_amount' => '200000.00',
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '200000.00',
            'direction' => 'debit',
        ]);

        $response = $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'loan', 'matched_to_id' => $loan->id],
        );

        $response->assertOk();
        $this->assertSame('loan', $response->json('data.matched_to.type'));
        $this->assertSame($loan->loan_reference, $response->json('data.matched_to.reference'));
    }

    #[Test]
    public function a_credit_line_cannot_be_matched_to_a_loan(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $loan = Loan::factory()->disbursed()->create([
            'disbursement_bank_account_id' => $account->id,
            'principal_amount' => '5000.00',
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'loan', 'matched_to_id' => $loan->id],
        )->assertStatus(422);
    }

    #[Test]
    public function a_line_cannot_be_matched_to_a_repayment_on_a_different_bank_account(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $otherAccount = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $otherAccount->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertStatus(422);
    }

    #[Test]
    public function a_line_cannot_be_matched_to_a_repayment_with_a_different_amount(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.01',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertStatus(422);
    }

    #[Test]
    public function an_unapproved_repayment_cannot_be_matched(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->verified()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertStatus(422);
    }

    #[Test]
    public function a_repayment_cannot_be_matched_to_two_statement_lines(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $firstLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);
        $secondLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$firstLine->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertOk();

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$secondLine->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertStatus(422);
    }

    #[Test]
    public function suggestions_exclude_a_repayment_already_matched_elsewhere(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $matchedLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);
        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$matchedLine->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertOk();

        $newLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $response = $this->getJson("/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$newLine->id}/suggestions");

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    #[Test]
    public function a_matched_line_can_be_unmatched_and_then_rematched(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create(['bank_account_id' => $account->id]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);

        $line = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertOk();

        $unmatchResponse = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/unmatch");
        $unmatchResponse->assertOk();
        $this->assertSame(BankStatementLineStatus::Unmatched->value, $unmatchResponse->json('data.status'));

        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertOk();
    }

    #[Test]
    public function an_unmatched_line_can_be_excluded_with_a_reason(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->create();
        $line = BankStatementLine::factory()->create(['bank_reconciliation_id' => $reconciliation->id]);

        $response = $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$line->id}/exclude",
            ['reason' => 'Bank charge with no corresponding Naipay transaction.'],
        );

        $response->assertOk();
        $this->assertSame(BankStatementLineStatus::Excluded->value, $response->json('data.status'));
    }

    // --- Submission and approval ---------------------------------------------------

    #[Test]
    public function submission_is_refused_while_any_line_remains_unmatched(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->create([
            'statement_opening_balance' => '0.00',
            'statement_closing_balance' => '0.00',
        ]);
        BankStatementLine::factory()->create(['bank_reconciliation_id' => $reconciliation->id]);

        $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/submit")->assertStatus(422);
    }

    #[Test]
    public function submission_is_refused_when_the_statement_does_not_reconcile(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->create([
            'statement_opening_balance' => '10000.00',
            // A credit of 5000 should bring this to 15000, not 20000.
            'statement_closing_balance' => '20000.00',
        ]);
        BankStatementLine::factory()->excluded()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/submit");

        $response->assertStatus(409);
    }

    #[Test]
    public function submission_succeeds_once_every_line_is_resolved_and_the_statement_reconciles(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $account = $this->collectionAccount();
        $reconciliation = BankReconciliation::factory()->create([
            'bank_account_id' => $account->id,
            'statement_opening_balance' => '10000.00',
            'statement_closing_balance' => '13000.00',
        ]);

        $repayment = Repayment::factory()->create([
            'receiving_bank_account_id' => $account->id,
            'amount' => '5000.00',
            'status' => RepaymentStatus::Approved,
        ]);
        $matchedLine = BankStatementLine::factory()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '5000.00',
            'direction' => 'credit',
        ]);
        $this->postJson(
            "/api/v1/admin/reconciliations/{$reconciliation->id}/lines/{$matchedLine->id}/match",
            ['matched_to_type' => 'repayment', 'matched_to_id' => $repayment->id],
        )->assertOk();

        BankStatementLine::factory()->excluded()->create([
            'bank_reconciliation_id' => $reconciliation->id,
            'bank_account_id' => $account->id,
            'amount' => '2000.00',
            'direction' => 'debit',
        ]);

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/submit");

        $response->assertOk();
        $this->assertSame(BankReconciliationStatus::PendingApproval->value, $response->json('data.status'));
    }

    #[Test]
    public function the_officer_who_prepared_a_reconciliation_cannot_approve_it(): void
    {
        $preparer = $this->actingAsStaffWith([Permission::ReconciliationMatch, Permission::ReconciliationApprove]);
        $reconciliation = BankReconciliation::factory()->pendingApproval()->create(['prepared_by' => $preparer->id]);

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/approve");

        $response->assertForbidden();
        $this->assertSame(BankReconciliationStatus::PendingApproval, $reconciliation->fresh()->status);
    }

    #[Test]
    public function a_different_officer_can_approve_a_submitted_reconciliation(): void
    {
        $preparer = Staff::factory()->create();
        $reconciliation = BankReconciliation::factory()->pendingApproval()->create(['prepared_by' => $preparer->id]);

        $this->actingAsStaffWith([Permission::ReconciliationApprove]);

        $response = $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/approve");

        $response->assertOk();
        $this->assertSame(BankReconciliationStatus::Approved->value, $response->json('data.status'));
    }

    #[Test]
    public function approving_requires_the_approve_permission(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationMatch]);
        $reconciliation = BankReconciliation::factory()->pendingApproval()->create();

        $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/approve")->assertForbidden();
    }

    #[Test]
    public function an_in_progress_reconciliation_cannot_be_approved_directly(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationApprove]);
        $reconciliation = BankReconciliation::factory()->create();

        $this->postJson("/api/v1/admin/reconciliations/{$reconciliation->id}/approve")->assertStatus(422);
    }

    // --- Reading and deletion --------------------------------------------------------

    #[Test]
    public function viewing_the_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/reconciliations')->assertUnauthorized();
    }

    #[Test]
    public function there_is_no_delete_endpoint_for_a_reconciliation(): void
    {
        $this->actingAsStaffWith([Permission::ReconciliationApprove]);
        $reconciliation = BankReconciliation::factory()->create();

        $this->deleteJson("/api/v1/admin/reconciliations/{$reconciliation->id}")->assertStatus(405);
    }

    private function collectionAccount(): BankAccount
    {
        return BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanRepaymentCollection,
        ]);
    }
}
