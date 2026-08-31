<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Ledger\Data\JournalLine;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard and reports.
 *
 * Every figure here is derived, never stored — what matters is that the
 * derivation is correct: the trial balance always balances because every
 * posting does, a loan's outstanding principal is counted in exactly one
 * status bucket, and a loan's place in the ageing buckets follows its
 * earliest unpaid overdue instalment, per naipay.loans.ageing_buckets and
 * naipay.loans.par_threshold_days rather than a hard-coded rule.
 */
final class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    // --- Dashboard -----------------------------------------------------------------

    #[Test]
    public function the_dashboard_requires_the_dashboard_view_permission(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();

        $this->actingAsStaffWith([]);
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    #[Test]
    public function the_dashboard_reports_loan_status_counts(): void
    {
        $this->actingAsStaffWith([Permission::DashboardView]);

        Loan::factory()->disbursed()->create(['outstanding_principal' => '50000.00']);
        Loan::factory()->disbursed()->create(['outstanding_principal' => '30000.00']);
        Loan::factory()->create();

        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.loans.'.LoanStatus::Disbursed->value));
        $this->assertSame(1, $response->json('data.loans.'.LoanStatus::PendingApproval->value));
        $this->assertSame('80000.00', $response->json('data.loans.total_outstanding_principal'));

        // Principal + interest: each disbursed loan carries 30,000 of factory
        // interest, so outstanding is 80,000 + 60,000 and the contractual
        // total is (150,000 + 30,000) * 2.
        $this->assertSame('140000.00', $response->json('data.loans.total_outstanding'));
        $this->assertSame('360000.00', $response->json('data.loans.total_principal_plus_interest'));
    }

    // --- Trial balance ---------------------------------------------------------------

    #[Test]
    public function the_trial_balance_always_balances_and_reflects_posted_entries(): void
    {
        $this->actingAsStaffWith([Permission::ReportsFinancial]);

        app(LedgerPostingService::class)->post(
            transactionType: 'test',
            description: 'A test disbursement.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('100000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('100000.00')),
            ],
        );

        $response = $this->getJson('/api/v1/admin/reports/trial-balance');

        $response->assertOk();
        $this->assertTrue($response->json('data.is_balanced'));
        $this->assertSame('100000.00', $response->json('data.total_debits'));
        $this->assertSame('100000.00', $response->json('data.total_credits'));

        $receivable = collect($response->json('data.accounts'))->firstWhere('code', StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE);
        $this->assertSame('100000.00', $receivable['balance']);
    }

    #[Test]
    public function the_trial_balance_excludes_postings_after_the_as_of_date(): void
    {
        $this->actingAsStaffWith([Permission::ReportsFinancial]);

        app(LedgerPostingService::class)->post(
            transactionType: 'test',
            description: 'Posted in the future relative to the report date.',
            lines: [
                JournalLine::debit(StandardAccounts::LOAN_PRINCIPAL_RECEIVABLE, Money::fromDecimal('50000.00')),
                JournalLine::credit(StandardAccounts::CASH_AT_BANK, Money::fromDecimal('50000.00')),
            ],
            transactionDate: Carbon::tomorrow(),
        );

        $response = $this->getJson('/api/v1/admin/reports/trial-balance?as_of='.now()->toDateString());

        $response->assertOk();
        $this->assertSame('0.00', $response->json('data.total_debits'));
    }

    #[Test]
    public function the_trial_balance_requires_the_financial_reports_permission(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        $this->getJson('/api/v1/admin/reports/trial-balance')->assertForbidden();
    }

    // --- Loan portfolio --------------------------------------------------------------

    #[Test]
    public function the_loan_portfolio_report_totals_outstanding_principal_by_status(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        Loan::factory()->disbursed()->create(['outstanding_principal' => '40000.00']);
        Loan::factory()->disbursed()->create(['outstanding_principal' => '60000.00']);
        Loan::factory()->create();

        $response = $this->getJson('/api/v1/admin/reports/loan-portfolio');

        $response->assertOk();
        $this->assertSame('100000.00', $response->json('data.total_outstanding_principal'));

        // Principal + interest: the two disbursed loans each carry 30,000 of
        // interest (from the factory), so outstanding is 160,000 and the full
        // contractual value is (150,000 + 30,000) * 2.
        $this->assertSame('160000.00', $response->json('data.total_outstanding'));
        $this->assertSame('360000.00', $response->json('data.total_principal_plus_interest'));

        $disbursedBucket = collect($response->json('data.by_status'))->firstWhere('status', LoanStatus::Disbursed->value);
        $this->assertSame(2, $disbursedBucket['count']);
        $this->assertSame('100000.00', $disbursedBucket['outstanding_principal']);
        $this->assertSame('160000.00', $disbursedBucket['outstanding']);
        $this->assertSame('360000.00', $disbursedBucket['principal_plus_interest']);
    }

    #[Test]
    public function the_loan_portfolio_report_groups_by_product(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        $product = LoanProduct::factory()->create(['name' => 'Daily Flex']);
        Loan::factory()->disbursed()->forProduct($product)->create(['outstanding_principal' => '25000.00']);

        $response = $this->getJson('/api/v1/admin/reports/loan-portfolio');

        $response->assertOk();
        $row = collect($response->json('data.by_product'))->firstWhere('name', 'Daily Flex');
        $this->assertNotNull($row);
        $this->assertSame('25000.00', $row['outstanding_principal']);
        // Outstanding + the 30,000 factory interest; full value 150,000 + 30,000.
        $this->assertSame('55000.00', $row['outstanding']);
        $this->assertSame('180000.00', $row['principal_plus_interest']);
    }

    // --- Collections -------------------------------------------------------------------

    #[Test]
    public function the_collections_report_totals_approved_repayments_within_the_period(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        Repayment::factory()->create([
            'status' => RepaymentStatus::Approved,
            'payment_date' => now()->toDateString(),
            'amount' => '10000.00',
            'allocated_principal' => '8000.00',
            'allocated_interest' => '2000.00',
            'allocated_fee' => '0.00',
            'allocated_excess' => '0.00',
            'allocated_unallocated' => '0.00',
        ]);

        // Outside the period, and not approved — neither should be counted.
        Repayment::factory()->create([
            'status' => RepaymentStatus::Approved,
            'payment_date' => now()->subMonths(2)->toDateString(),
            'amount' => '5000.00',
        ]);
        Repayment::factory()->create([
            'status' => RepaymentStatus::Recorded,
            'payment_date' => now()->toDateString(),
            'amount' => '3000.00',
        ]);

        $response = $this->getJson('/api/v1/admin/reports/collections?from='.now()->startOfMonth()->toDateString().'&to='.now()->endOfMonth()->toDateString());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.count'));
        $this->assertSame('10000.00', $response->json('data.total_collected'));
        $this->assertSame('8000.00', $response->json('data.allocated_principal'));
        $this->assertSame('2000.00', $response->json('data.allocated_interest'));
    }

    // --- Delinquency -----------------------------------------------------------------

    #[Test]
    public function a_loan_is_bucketed_by_its_earliest_overdue_instalment(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        $loan = Loan::factory()->disbursed()->create(['outstanding_principal' => '20000.00']);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            // 45 days overdue: falls in the "31-60 days" bucket.
            'due_date' => now()->subDays(45)->toDateString(),
            'principal_due' => '10000.00',
            'interest_due' => '2000.00',
            'fee_due' => '0.00',
        ]);

        $response = $this->getJson('/api/v1/admin/reports/delinquency');

        $response->assertOk();
        $bucket = collect($response->json('data.ageing_buckets'))->firstWhere('label', '31-60 days');
        $this->assertSame(1, $bucket['count']);
        $this->assertSame('20000.00', $bucket['outstanding_principal']);
    }

    #[Test]
    public function portfolio_at_risk_only_counts_loans_beyond_the_configured_threshold(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        $atRisk = Loan::factory()->disbursed()->create(['outstanding_principal' => '15000.00']);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $atRisk->id,
            'installment_number' => 1,
            'due_date' => now()->subDays(35)->toDateString(),
            'principal_due' => '15000.00',
            'interest_due' => '0.00',
            'fee_due' => '0.00',
        ]);

        $notAtRisk = Loan::factory()->disbursed()->create(['outstanding_principal' => '5000.00']);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $notAtRisk->id,
            'installment_number' => 1,
            'due_date' => now()->subDays(5)->toDateString(),
            'principal_due' => '5000.00',
            'interest_due' => '0.00',
            'fee_due' => '0.00',
        ]);

        $response = $this->getJson('/api/v1/admin/reports/delinquency');

        $response->assertOk();
        $this->assertSame(30, $response->json('data.portfolio_at_risk.threshold_days'));
        $this->assertSame('15000.00', $response->json('data.portfolio_at_risk.outstanding_principal'));
    }

    #[Test]
    public function a_fully_paid_loan_with_no_overdue_instalments_counts_as_current(): void
    {
        $this->actingAsStaffWith([Permission::ReportsView]);

        $loan = Loan::factory()->disbursed()->create(['outstanding_principal' => '10000.00']);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'due_date' => now()->addDays(5)->toDateString(),
            'principal_due' => '10000.00',
            'interest_due' => '0.00',
            'fee_due' => '0.00',
        ]);

        $response = $this->getJson('/api/v1/admin/reports/delinquency');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.current.count'));
        $this->assertSame('10000.00', $response->json('data.current.outstanding_principal'));

        foreach ($response->json('data.ageing_buckets') as $bucket) {
            $this->assertSame(0, $bucket['count']);
        }
    }
}
