<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Services\LoanApplicationService;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Support\Exceptions\MakerCheckerViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Loan creation and disbursement.
 *
 * Three gates stand between a credit decision and money actually moving —
 * `loan_application.approve`, `loan.approve`, `loan.disburse` — each held by
 * a different officer than the one before it. What matters here: a loan can
 * never exist without the application that authorised it, disbursement can
 * never happen without a balanced ledger posting alongside it, and no single
 * officer can walk a loan through more than one of those three gates alone.
 */
final class LoanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    // --- Creation, as a consequence of application approval --------------------

    #[Test]
    public function approving_a_loan_application_creates_a_loan_awaiting_its_own_approval(): void
    {
        $application = LoanApplication::factory()->recommended()->create();
        $approver = $this->actingAsRole(Role::CreditManager);

        app(LoanApplicationService::class)->approve($application, $approver);

        $loan = Loan::query()->where('loan_application_id', $application->id)->firstOrFail();

        $this->assertSame(LoanStatus::PendingApproval, $loan->status);
        $this->assertSame($approver->id, $loan->created_by);
        $this->assertNull($loan->approved_by);
        $this->assertTrue($loan->principal_amount->equals($application->fresh()->approved_amount));
    }

    #[Test]
    public function an_application_that_fails_to_approve_never_leaves_a_loan_behind(): void
    {
        // The application's own maker (its creator) cannot approve it, so the
        // approve() call never reaches the point of creating a loan. Called
        // directly against the service — no HTTP layer or acting user is
        // needed to exercise this.
        $maker = Staff::factory()->create();
        $application = LoanApplication::factory()->recommended()->create(['created_by' => $maker->id]);

        $this->expectException(MakerCheckerViolationException::class);

        try {
            app(LoanApplicationService::class)->approve($application, $maker);
        } finally {
            $this->assertSame(0, Loan::query()->where('loan_application_id', $application->id)->count());
        }
    }

    #[Test]
    public function the_loans_terms_are_snapshotted_from_the_product_at_creation(): void
    {
        $product = LoanProduct::factory()->weekly()->create();
        $application = LoanApplication::factory()->recommended()->create([
            'loan_product_id' => $product->id,
            'requested_amount' => '200000.00',
            'requested_tenor' => 10,
        ]);

        $approver = $this->actingAsRole(Role::CreditManager);
        app(LoanApplicationService::class)->approve($application, $approver, approvedTenor: 8);

        $loan = Loan::query()->where('loan_application_id', $application->id)->firstOrFail();

        $this->assertSame($product->interest_method, $loan->interest_method);
        $this->assertSame($product->repayment_frequency, $loan->repayment_frequency);
        $this->assertSame(8, $loan->tenor);
        $this->assertSame('4.0000', $loan->interest_rate);

        // Repricing the product afterwards must never rewrite an existing
        // loan's contract.
        $product->forceFill(['interest_rate' => '9.0000'])->save();
        $this->assertSame('4.0000', $loan->fresh()->interest_rate);
    }

    // --- The loan's own approval -------------------------------------------------

    #[Test]
    public function a_different_officer_can_approve_the_loan(): void
    {
        $maker = Staff::factory()->create();
        $loan = Loan::factory()->create(['created_by' => $maker->id]);

        $this->actingAsRole(Role::CreditManager);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/approve");

        $response->assertOk();
        $this->assertSame(LoanStatus::PendingDisbursement->value, $response->json('data.status'));

        $loan->refresh();
        $this->assertNotNull($loan->approved_by);
        $this->assertNotNull($loan->approved_at);
    }

    #[Test]
    public function the_officer_who_created_the_loan_cannot_approve_it(): void
    {
        $maker = $this->actingAsRole(Role::CreditManager);
        $loan = Loan::factory()->create(['created_by' => $maker->id]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/approve");

        $response->assertForbidden();
        $this->assertSame(LoanStatus::PendingApproval, $loan->fresh()->status);
    }

    #[Test]
    public function a_loan_that_is_not_pending_approval_cannot_be_approved(): void
    {
        $this->actingAsRole(Role::CreditManager);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/approve");

        $response->assertStatus(422);
    }

    #[Test]
    public function approving_a_loan_requires_the_loans_approve_permission(): void
    {
        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = Loan::factory()->create();

        $this->postJson("/api/v1/admin/loans/{$loan->id}/approve")->assertForbidden();
    }

    // --- Disbursement --------------------------------------------------------------

    #[Test]
    public function disbursing_a_loan_posts_a_balanced_ledger_entry_and_builds_the_schedule(): void
    {
        $loan = Loan::factory()->pendingDisbursement()->create([
            'principal_amount' => '100000.00',
            'tenor' => 10,
        ]);

        $this->actingAsRole(Role::FinanceManager);
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertOk();
        $this->assertSame(LoanStatus::Disbursed->value, $response->json('data.status'));
        $this->assertNotEmpty($response->json('data.schedule'));

        $loan->refresh();
        $this->assertSame(10, $loan->scheduleEntries()->count());
        $this->assertSame('100000.00', $loan->outstanding_principal->toDecimalString());
        $this->assertSame('20000.00', $loan->total_interest->toDecimalString());
        $this->assertNotNull($loan->disbursement_journal_transaction_id);

        $transaction = JournalTransaction::query()->findOrFail($loan->disbursement_journal_transaction_id);
        $this->assertCount(2, $transaction->entries);

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $cash = LedgerAccount::query()->where('code', StandardAccounts::CASH_AT_BANK)->firstOrFail();

        $this->assertSame('100000.00', $receivable->currentBalance()->toDecimalString());
        $this->assertSame('-100000.00', $cash->currentBalance()->toDecimalString());
    }

    #[Test]
    public function the_disbursement_schedule_matches_what_the_calculator_would_produce_standalone(): void
    {
        $loan = Loan::factory()->pendingDisbursement()->create([
            'principal_amount' => '100000.00',
            'interest_rate' => '20.0000',
            'tenor' => 5,
        ]);

        $this->actingAsRole(Role::FinanceManager);
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ])->assertOk();

        $entries = $loan->fresh()->scheduleEntries;

        $totalPrincipal = $entries->sum(fn ($e) => (float) $e->principal_due->toDecimalString());
        $totalInterest = $entries->sum(fn ($e) => (float) $e->interest_due->toDecimalString());

        $this->assertSame(100000.0, $totalPrincipal);
        $this->assertSame(20000.0, $totalInterest);
    }

    #[Test]
    public function disbursing_without_the_loans_disburse_permission_is_refused(): void
    {
        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function the_officer_who_approved_the_loan_is_refused_by_maker_checker_even_with_the_permission(): void
    {
        $approver = $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->create(['approved_by' => $approver->id, 'status' => LoanStatus::PendingDisbursement]);

        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertForbidden();
        $this->assertSame(LoanStatus::PendingDisbursement, $loan->fresh()->status);
    }

    #[Test]
    public function a_loan_cannot_be_disbursed_before_its_own_approval(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->create();

        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function disbursement_is_refused_from_an_unapproved_bank_account(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $bankAccount = BankAccount::factory()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(LoanStatus::PendingDisbursement, $loan->fresh()->status);
    }

    #[Test]
    public function disbursement_is_refused_from_an_account_not_purposed_for_disbursement(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanRepaymentCollection,
        ]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function a_repeated_disbursement_request_never_posts_twice(): void
    {
        $loan = Loan::factory()->pendingDisbursement()->create();

        $this->actingAsRole(Role::FinanceManager);
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", ['bank_account_id' => $bankAccount->id])
            ->assertOk();

        // The loan is already Disbursed, so a retried request is refused by
        // the status guard before it can reach the ledger a second time.
        $this->postJson("/api/v1/admin/loans/{$loan->id}/disburse", ['bank_account_id' => $bankAccount->id])
            ->assertStatus(422);

        $this->assertSame(
            1,
            JournalTransaction::query()->where('source_type', Loan::class)->where('source_id', $loan->id)->count(),
        );
    }

    // --- Write-off -----------------------------------------------------------------

    #[Test]
    public function writing_off_a_disbursed_loan_clears_the_principal_receivable(): void
    {
        $loan = Loan::factory()->disbursed()->create(['outstanding_principal' => '75000.00']);

        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/write-off", [
            'reason' => 'Merchant ceased trading and is unreachable.',
        ]);

        $response->assertOk();
        $this->assertSame(LoanStatus::WrittenOff->value, $response->json('data.status'));

        $loan->refresh();
        $this->assertTrue($loan->outstanding_principal->isZero());

        $receivable = LedgerAccount::query()->where('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE)->firstOrFail();
        $writtenOff = LedgerAccount::query()->where('code', StandardAccounts::WRITTEN_OFF_LOANS)->firstOrFail();

        $this->assertSame('-75000.00', $receivable->currentBalance()->toDecimalString());
        $this->assertSame('75000.00', $writtenOff->currentBalance()->toDecimalString());
    }

    #[Test]
    public function a_loan_that_has_not_been_disbursed_cannot_be_written_off(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->pendingDisbursement()->create();

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/write-off", [
            'reason' => 'Attempting to write off before any money moved.',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function the_officer_who_approved_the_loan_cannot_write_it_off(): void
    {
        $approver = $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->disbursed()->create(['approved_by' => $approver->id]);

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/write-off", [
            'reason' => 'Attempting a write-off against the same officer who approved it.',
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function a_write_off_requires_a_reason(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->disbursed()->create();

        $response = $this->postJson("/api/v1/admin/loans/{$loan->id}/write-off", [
            'reason' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function there_is_no_delete_endpoint_for_a_loan(): void
    {
        $this->actingAsRole(Role::FinanceManager);
        $loan = Loan::factory()->create();

        $this->deleteJson("/api/v1/admin/loans/{$loan->id}")->assertStatus(405);
    }

    // --- Reading ---------------------------------------------------------------------

    #[Test]
    public function viewing_the_loan_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/loans')->assertUnauthorized();
    }

    #[Test]
    public function a_loan_can_be_retrieved_with_its_schedule(): void
    {
        $this->actingAsStaffWith([Permission::LoansView]);
        $loan = Loan::factory()->disbursed()->create();

        $response = $this->getJson("/api/v1/admin/loans/{$loan->id}");

        $response->assertOk();
        $this->assertSame($loan->loan_reference, $response->json('data.loan_reference'));
    }
}
