<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Notifications\LoanApplicationApprovedNotification;
use App\Domains\LoanApplications\Notifications\LoanApplicationRejectedNotification;
use App\Domains\LoanApplications\Services\LoanApplicationService;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Loans\Notifications\LoanDisbursedNotification;
use App\Domains\Loans\Services\LoanDisbursementService;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Notifications\MerchantApprovedNotification;
use App\Domains\Merchants\Services\MerchantOnboardingService;
use App\Domains\Receipts\Models\Receipt;
use App\Domains\Receipts\Services\ReceiptService;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Domains\Repayments\Notifications\RepaymentReceiptNotification;
use App\Domains\Repayments\Services\RepaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notifications wired into the events that already move money or change a
 * merchant's standing. Nothing here re-tests the underlying workflow — that
 * is covered where each domain's own tests live — only that the right
 * notification reaches the right merchant when it happens.
 */
final class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function approving_a_repayment_emails_the_merchant_their_receipt_and_creates_one(): void
    {
        $loan = Loan::factory()->disbursed()->create();
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->toDateString(),
            'principal_due' => '5000.00',
            'interest_due' => '1000.00',
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

        $approver = $this->actingAsRole(Role::FinanceManager);
        app(RepaymentService::class)->approve($repayment->fresh(), $approver);

        $merchant = Merchant::query()->findOrFail($loan->merchant_id);

        Notification::assertSentTo($merchant, RepaymentReceiptNotification::class);
        $this->assertSame(1, Receipt::query()->where('repayment_id', $repayment->id)->count());
    }

    #[Test]
    public function disbursing_a_loan_emails_the_merchant(): void
    {
        $loan = Loan::factory()->pendingDisbursement()->create();
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $actor = $this->actingAsRole(Role::FinanceManager);
        app(LoanDisbursementService::class)->disburse($loan, $bankAccount, $actor);

        $merchant = Merchant::query()->findOrFail($loan->merchant_id);

        Notification::assertSentTo($merchant, LoanDisbursedNotification::class);
    }

    #[Test]
    public function approving_a_loan_application_emails_the_merchant(): void
    {
        $application = LoanApplication::factory()->recommended()->create();
        $approver = $this->actingAsRole(Role::CreditManager);

        app(LoanApplicationService::class)->approve($application, $approver);

        $merchant = Merchant::query()->findOrFail($application->merchant_id);

        Notification::assertSentTo($merchant, LoanApplicationApprovedNotification::class);
    }

    #[Test]
    public function rejecting_a_loan_application_emails_the_merchant(): void
    {
        $application = LoanApplication::factory()->recommended()->create();
        $actor = $this->actingAsRole(Role::CreditManager);

        app(LoanApplicationService::class)->reject($application, 'Insufficient trading history to support this facility.', $actor);

        $merchant = Merchant::query()->findOrFail($application->merchant_id);

        Notification::assertSentTo($merchant, LoanApplicationRejectedNotification::class);
    }

    #[Test]
    public function approving_a_merchants_onboarding_emails_them(): void
    {
        $merchant = Merchant::factory()->pendingApproval()->create();
        Business::factory()->verified()->create(['merchant_id' => $merchant->id]);

        $actor = $this->actingAsRole(Role::OperationsManager);
        app(MerchantOnboardingService::class)->approve($merchant, $actor);

        Notification::assertSentTo($merchant->fresh(), MerchantApprovedNotification::class);
    }

    #[Test]
    public function generating_a_receipt_twice_for_the_same_repayment_is_idempotent(): void
    {
        $repayment = Repayment::factory()->create(['status' => RepaymentStatus::Approved]);

        $first = app(ReceiptService::class)->generateFor($repayment);
        $second = app(ReceiptService::class)->generateFor($repayment);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Receipt::query()->where('repayment_id', $repayment->id)->count());
    }
}
