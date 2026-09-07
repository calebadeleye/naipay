<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Support\StandardAccounts;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Models\LoanScheduleEntry;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Repayments\Enums\RepaymentStatus;
use App\Domains\Repayments\Models\Repayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The portfolio analytics endpoint, exercised end to end against MySQL.
 *
 * A single fixture portfolio (see seedPortfolio()) with hand-computed
 * expectations covers the financial definitions the engineering brief pins
 * down — outstanding vs receivable, schedule-driven overdue, PAR on
 * outstanding exposure, ageing, collection rate, and every breakdown — plus
 * the edge cases (fully repaid, written off, one borrower with several loans,
 * empty book) and tenant/branch isolation.
 *
 * Clock frozen at 2026-06-15. The default window is therefore 2026-06-01 .. 15.
 */
final class LoanPortfolioAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/v1/admin/reports/loan-portfolio/analytics';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Authorisation ─────────────────────────────────────────────────────

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->getJson($this->url)->assertUnauthorized();
    }

    #[Test]
    public function it_requires_the_reports_view_permission(): void
    {
        $this->actingAsStaffWith([Permission::DashboardView], ['access_scope' => AccessScope::Global]);

        $this->getJson($this->url)->assertForbidden();
    }

    // ── KPIs and definitions ──────────────────────────────────────────────

    #[Test]
    public function the_headline_kpis_match_the_hand_computed_portfolio(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $kpis = $this->getJson($this->url)->assertOk()->json('data.kpis');

        // Disbursed in the window: L1 100k + L2 40k + L4 25k (written off still counts).
        $this->assertSame('165000.00', $kpis['total_disbursed']['value']);
        // Outstanding principal, disbursed loans only: 100k + 40k + 0.
        $this->assertSame('140000.00', $kpis['outstanding_principal']['value']);
        // Current receivable: principal + interest + fees still owed.
        $this->assertSame('168000.00', $kpis['current_receivable']['value']);
        // Collected in the window: 15k on L1 + 5k recovery on L4. Reversed/recorded excluded.
        $this->assertSame('20000.00', $kpis['total_collected']['value']);
        // Overdue: the unpaid portion of L1's 2026-06-05 instalment only.
        $this->assertSame('60000.00', $kpis['overdue_amount']['value']);
        // Active = disbursed with principal still outstanding: L1, L2.
        $this->assertSame('2', $kpis['active_loans']['value']);
        // Active borrowers: L1 and L2 are the same merchant.
        $this->assertSame('1', $kpis['active_borrowers']['value']);
        // Nothing is 30+ days overdue.
        $this->assertSame('0.00', $kpis['portfolio_at_risk_30']['value']);
    }

    #[Test]
    public function receivable_breaks_principal_interest_and_contracted_apart(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $receivable = $this->getJson($this->url)->assertOk()->json('data.receivable');

        $this->assertSame('140000.00', $receivable['outstanding_principal']);
        $this->assertSame('28000.00', $receivable['outstanding_interest']);
        $this->assertSame('168000.00', $receivable['current_receivable']);
        // Contracted = total_payable over active (disbursed) loans:
        // 120k (L1) + 48k (L2) + 30k (L3). A written-off contract is terminated.
        $this->assertSame('198000.00', $receivable['contracted_receivable']);
    }

    // ── Risk ─────────────────────────────────────────────────────────────

    #[Test]
    public function par_uses_outstanding_principal_and_the_right_day_bands(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $bands = collect($this->getJson($this->url)->assertOk()->json('data.risk'))
            ->keyBy('threshold_days');

        // L1's earliest overdue instalment is 10 days late; its full outstanding
        // principal (100k, not the 50k instalment, not the 100k original) is at risk.
        $this->assertSame('100000.00', $bands[1]['at_risk_amount']);
        $this->assertSame('100000.00', $bands[7]['at_risk_amount']);
        $this->assertSame('0.00', $bands[30]['at_risk_amount']);
        $this->assertSame('0.00', $bands[60]['at_risk_amount']);
        $this->assertSame('0.00', $bands[90]['at_risk_amount']);

        // 100k of 140k outstanding.
        $this->assertEqualsWithDelta(71.43, $bands[1]['percentage_of_outstanding'], 0.01);
    }

    // ── Ageing ───────────────────────────────────────────────────────────

    #[Test]
    public function ageing_places_each_loan_in_exactly_one_bucket(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $buckets = collect($this->getJson($this->url)->assertOk()->json('data.aging.buckets'))
            ->keyBy('label');

        // Current: L2 and L3 (neither overdue).
        $this->assertSame(2, $buckets['Current']['loan_count']);
        $this->assertSame('40000.00', $buckets['Current']['outstanding_principal']);

        // L1 is 10 days down.
        $this->assertSame(1, $buckets['1-30 days']['loan_count']);
        $this->assertSame('100000.00', $buckets['1-30 days']['outstanding_principal']);

        $this->assertSame(0, $buckets['31-60 days']['loan_count']);
    }

    // ── Collection performance ───────────────────────────────────────────

    #[Test]
    public function collection_rate_is_collected_over_scheduled_for_the_window(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $perf = $this->getJson($this->url)->assertOk()->json('data.collection_performance');

        // Due in June: L1 #1 (60k) + L3 #1 (30k). L2 #1 is due in July.
        $this->assertSame('90000.00', $perf['expected']);
        $this->assertSame('20000.00', $perf['collected']);
        $this->assertEqualsWithDelta(22.22, $perf['collection_rate'], 0.01);
        $this->assertSame(1, $perf['overdue_loan_count']);
    }

    // ── Breakdowns ───────────────────────────────────────────────────────

    #[Test]
    public function by_status_only_lists_real_lifecycle_states(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $rows = collect($this->getJson($this->url)->assertOk()->json('data.by_status'))->keyBy('status');

        $this->assertEqualsCanonicalizing(
            ['pending_approval', 'pending_disbursement', 'disbursed', 'written_off'],
            $rows->keys()->all(),
        );
        $this->assertSame(3, $rows['disbursed']['loan_count']);
        $this->assertSame('140000.00', $rows['disbursed']['outstanding_principal']);
        $this->assertSame('28000.00', $rows['disbursed']['outstanding_interest']);
        $this->assertSame('168000.00', $rows['disbursed']['total_receivable']);
        $this->assertSame(1, $rows['written_off']['loan_count']);
    }

    #[Test]
    public function by_product_officer_and_branch_all_aggregate_the_same_single_group(): void
    {
        $context = $this->seedPortfolio();
        $this->actingAsAnalyst();

        $data = $this->getJson($this->url)->assertOk()->json('data');

        foreach (['by_product', 'by_loan_officer', 'by_branch'] as $section) {
            $this->assertCount(1, $data[$section], "{$section} should have one group");
            $row = $data[$section][0];

            $this->assertSame(4, $row['loans'], "{$section} loans");
            $this->assertSame(3, $row['borrowers'], "{$section} borrowers");
            $this->assertSame('165000.00', $row['total_disbursed'], "{$section} disbursed");
            $this->assertSame('140000.00', $row['outstanding_principal'], "{$section} outstanding");
            $this->assertSame('20000.00', $row['collected'], "{$section} collected");
            $this->assertSame('60000.00', $row['overdue'], "{$section} overdue");
        }

        $this->assertSame('Daily Trader', $data['by_product'][0]['name']);
        $this->assertSame('Ada Obi', $data['by_loan_officer'][0]['name']);
        $this->assertSame('Ikeja', $data['by_branch'][0]['name']);
        $this->assertSame($context['product']->id, $data['by_product'][0]['id']);
    }

    // ── Borrowers ────────────────────────────────────────────────────────

    #[Test]
    public function borrower_metrics_never_double_count_a_borrower(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $b = $this->getJson($this->url)->assertOk()->json('data.borrowers');

        $this->assertSame(3, $b['total_borrowers']);
        $this->assertSame(1, $b['active_borrowers']);
        // First-ever disbursement inside the window: borrower A (06-10), borrower C (06-02).
        $this->assertSame(2, $b['new_borrowers']);
        $this->assertSame(0, $b['returning_borrowers']);
        $this->assertSame(1, $b['borrowers_with_overdue']);
        $this->assertSame(1, $b['borrowers_with_multiple_active_loans']);
        // 1 of 3 borrowers has more than one loan.
        $this->assertEqualsWithDelta(33.33, $b['repeat_borrower_rate'], 0.01);
        $this->assertSame('140000.00', $b['average_outstanding_per_borrower']);
        // 195k disbursed across 4 loans.
        $this->assertSame('48750.00', $b['average_loan_size']);
    }

    // ── Loan performance, interest/fees, write-off ───────────────────────

    #[Test]
    public function loan_performance_derives_completion_and_write_off_rates(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $p = $this->getJson($this->url)->assertOk()->json('data.loan_performance');

        $this->assertSame('48750.00', $p['average_loan_amount']);
        $this->assertSame(2, $p['active_loans']);
        $this->assertSame(1, $p['completed_loans']);
        $this->assertSame(1, $p['written_off_loans']);
        $this->assertEqualsWithDelta(25.0, $p['loan_completion_rate'], 0.01);
        $this->assertEqualsWithDelta(25.0, $p['write_off_rate'], 0.01);
    }

    #[Test]
    public function interest_and_fee_analytics_split_contracted_collected_outstanding(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $i = $this->getJson($this->url)->assertOk()->json('data.interest_and_fees');

        $this->assertSame('28000.00', $i['interest_contracted']);
        $this->assertSame('5000.00', $i['interest_collected']);
        $this->assertSame('28000.00', $i['interest_outstanding']);
        $this->assertSame('0.00', $i['fees_contracted']);
    }

    #[Test]
    public function write_off_and_recovery_reads_the_amount_from_the_ledger(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $w = $this->getJson($this->url)->assertOk()->json('data.write_off_and_recovery');

        $this->assertSame('25000.00', $w['written_off_amount']);
        $this->assertSame(1, $w['written_off_loans']);
        $this->assertSame('5000.00', $w['recovered_amount']);
        $this->assertEqualsWithDelta(20.0, $w['recovery_rate'], 0.01);
    }

    #[Test]
    public function the_default_rate_is_reported_as_unavailable(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $this->getJson($this->url)->assertOk()
            ->assertJsonPath('data.meta.unavailable.default_rate', fn ($v): bool => is_string($v) && $v !== '');
    }

    // ── Filters ──────────────────────────────────────────────────────────

    #[Test]
    public function a_product_filter_narrows_every_section(): void
    {
        $context = $this->seedPortfolio();
        $otherProduct = LoanProduct::factory()->create(['name' => 'Weekly']);
        $this->actingAsAnalyst();

        $data = $this->getJson($this->url.'?loan_product_id='.$otherProduct->id)->assertOk()->json('data');

        $this->assertSame('0.00', $data['kpis']['outstanding_principal']['value']);
        $this->assertSame('0.00', $data['kpis']['total_disbursed']['value']);
        $this->assertSame([], $data['by_product']);
        $this->assertSame(0, $data['borrowers']['total_borrowers']);
        $this->assertSame($otherProduct->id, $data['filters']['loan_product_id']);
    }

    #[Test]
    public function a_status_filter_restricts_the_portfolio_to_that_state(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $data = $this->getJson($this->url.'?status=written_off')->assertOk()->json('data');

        $this->assertSame('0.00', $data['kpis']['outstanding_principal']['value']);
        $rows = collect($data['by_status'])->keyBy('status');
        $this->assertSame(1, $rows['written_off']['loan_count']);
        $this->assertArrayNotHasKey('disbursed', $rows->all());
    }

    #[Test]
    public function a_date_range_filter_moves_the_flow_window(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        // Last month: only L3 was disbursed (2026-05-20).
        $data = $this->getJson($this->url.'?range=last_month')->assertOk()->json('data');

        $this->assertSame('30000.00', $data['kpis']['total_disbursed']['value']);
        $this->assertSame('2026-05-01', $data['filters']['date_from']);
        $this->assertSame('2026-05-31', $data['filters']['date_to']);
    }

    #[Test]
    public function an_overdue_repayment_status_filter_keeps_only_delinquent_loans(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $data = $this->getJson($this->url.'?repayment_status=overdue')->assertOk()->json('data');

        // Only L1 qualifies.
        $this->assertSame('100000.00', $data['kpis']['outstanding_principal']['value']);
        $this->assertSame('1', $data['kpis']['active_loans']['value']);
    }

    // ── Isolation & edges ────────────────────────────────────────────────

    #[Test]
    public function a_branch_scoped_officer_only_sees_their_own_branch(): void
    {
        $context = $this->seedPortfolio();

        $otherBranch = Branch::factory()->create(['name' => 'Abuja']);
        $otherMerchant = Merchant::factory()->create(['branch_id' => $otherBranch->id]);
        $this->loan($otherBranch, $context['product'], $otherMerchant, [
            'principal_amount' => '500000.00', 'outstanding_principal' => '500000.00',
            'outstanding_interest' => '0.00', 'outstanding_fees' => '0.00',
            'total_interest' => '0.00', 'total_fees' => '0.00', 'total_payable' => '500000.00',
            'disbursement_date' => '2026-06-05', 'maturity_date' => '2026-07-05',
        ]);

        // Scoped to Ikeja only.
        $this->actingAsAnalyst(['access_scope' => AccessScope::Branch, 'branch_id' => $context['branch']->id]);

        $data = $this->getJson($this->url)->assertOk()->json('data');

        // The 500k Abuja loan is invisible; Ikeja totals are unchanged.
        $this->assertSame('140000.00', $data['kpis']['outstanding_principal']['value']);
        $this->assertCount(1, $data['by_branch']);
        $this->assertSame('Ikeja', $data['by_branch'][0]['name']);
    }

    #[Test]
    public function an_unassigned_branch_scoped_officer_sees_nothing(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst(['access_scope' => AccessScope::Branch, 'branch_id' => null]);

        $data = $this->getJson($this->url)->assertOk()->json('data');

        $this->assertSame('0.00', $data['kpis']['outstanding_principal']['value']);
        $this->assertSame([], $data['by_branch']);
    }

    #[Test]
    public function an_empty_book_returns_zeros_not_errors(): void
    {
        $this->actingAsAnalyst();

        $data = $this->getJson($this->url)->assertOk()->json('data');

        $this->assertSame('0.00', $data['kpis']['outstanding_principal']['value']);
        $this->assertSame('0.00', $data['kpis']['total_collected']['value']);
        $this->assertNull($data['collection_performance']['collection_rate']);
        $this->assertNull($data['risk'][0]['percentage_of_outstanding']);
        $this->assertSame([], $data['by_product']);
        $this->assertSame(0, $data['borrowers']['total_borrowers']);
    }

    #[Test]
    public function the_response_echoes_the_resolved_filters_and_period_semantics(): void
    {
        $this->seedPortfolio();
        $this->actingAsAnalyst();

        $meta = $this->getJson($this->url)->assertOk()->json('data');

        $this->assertSame('this_month', $meta['filters']['range']);
        $this->assertSame('2026-06-01', $meta['meta']['period_semantics']['flow_window']['from']);
        $this->assertSame('2026-06-15', $meta['meta']['period_semantics']['stock_as_of']);
        $this->assertSame('NGN', $meta['meta']['currency']);
    }

    // ── Fixture ────────────────────────────────────────────────────────────

    /**
     * @return array{branch: Branch, officer: Staff, product: LoanProduct}
     */
    private function seedPortfolio(): array
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $branch = Branch::factory()->create(['name' => 'Ikeja']);
        $officer = Staff::factory()->create(['first_name' => 'Ada', 'last_name' => 'Obi']);
        $product = LoanProduct::factory()->create(['name' => 'Daily Trader']);

        $borrowerA = Merchant::factory()->create(['assigned_officer_id' => $officer->id, 'branch_id' => $branch->id]);
        $borrowerB = Merchant::factory()->create(['assigned_officer_id' => $officer->id, 'branch_id' => $branch->id]);
        $borrowerC = Merchant::factory()->create(['assigned_officer_id' => $officer->id, 'branch_id' => $branch->id]);

        // L1 — active, one instalment 10 days overdue.
        $l1 = $this->loan($branch, $product, $borrowerA, [
            'principal_amount' => '100000.00',
            'total_interest' => '20000.00', 'total_fees' => '0.00', 'total_payable' => '120000.00',
            'outstanding_principal' => '100000.00', 'outstanding_interest' => '20000.00', 'outstanding_fees' => '0.00',
            'disbursement_date' => '2026-06-10', 'maturity_date' => '2026-07-10',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $l1->id, 'installment_number' => 1, 'due_date' => '2026-06-05',
            'principal_due' => '50000.00', 'interest_due' => '10000.00', 'fee_due' => '0.00',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $l1->id, 'installment_number' => 2, 'due_date' => '2026-07-05',
            'principal_due' => '50000.00', 'interest_due' => '10000.00', 'fee_due' => '0.00',
        ]);

        // L2 — active, same borrower as L1, nothing overdue.
        $l2 = $this->loan($branch, $product, $borrowerA, [
            'principal_amount' => '40000.00',
            'total_interest' => '8000.00', 'total_fees' => '0.00', 'total_payable' => '48000.00',
            'outstanding_principal' => '40000.00', 'outstanding_interest' => '8000.00', 'outstanding_fees' => '0.00',
            'disbursement_date' => '2026-06-12', 'maturity_date' => '2026-07-12',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $l2->id, 'installment_number' => 1, 'due_date' => '2026-07-01',
            'principal_due' => '40000.00', 'interest_due' => '8000.00', 'fee_due' => '0.00',
        ]);

        // L3 — fully repaid (zero balances), disbursed last month, borrower B.
        $l3 = $this->loan($branch, $product, $borrowerB, [
            'principal_amount' => '30000.00',
            'total_interest' => '0.00', 'total_fees' => '0.00', 'total_payable' => '30000.00',
            'outstanding_principal' => '0.00', 'outstanding_interest' => '0.00', 'outstanding_fees' => '0.00',
            'disbursement_date' => '2026-05-20', 'maturity_date' => '2026-06-20',
        ]);
        LoanScheduleEntry::factory()->create([
            'loan_id' => $l3->id, 'installment_number' => 1, 'due_date' => '2026-06-01',
            'principal_due' => '30000.00', 'interest_due' => '0.00', 'fee_due' => '0.00',
            'principal_paid' => '30000.00', 'interest_paid' => '0.00', 'fee_paid' => '0.00',
        ]);

        // L4 — written off this month, borrower C.
        $l4 = $this->loan($branch, $product, $borrowerC, [
            'principal_amount' => '25000.00',
            'total_interest' => '0.00', 'total_fees' => '0.00', 'total_payable' => '25000.00',
            'outstanding_principal' => '0.00', 'outstanding_interest' => '0.00', 'outstanding_fees' => '0.00',
            'disbursement_date' => '2026-06-02', 'maturity_date' => '2026-07-02',
            'status' => LoanStatus::WrittenOff, 'written_off_at' => '2026-06-08 09:00:00',
        ]);
        $this->postWriteOffToLedger($l4, '25000.00', $branch->id);

        // Collections this month: one ordinary repayment on L1, one recovery on L4.
        $this->approvedRepayment($l1, '15000.00', '2026-06-11', principal: '10000.00', interest: '5000.00');
        $this->approvedRepayment($l4, '5000.00', '2026-06-13', principal: '5000.00');

        // Noise that must never be counted as a collection.
        Repayment::factory()->create([
            'loan_id' => $l1->id, 'amount' => '99999.00', 'payment_date' => '2026-06-11',
            'status' => RepaymentStatus::Reversed, 'reversed_at' => now(),
        ]);
        Repayment::factory()->create([
            'loan_id' => $l1->id, 'amount' => '88888.00', 'payment_date' => '2026-06-11',
            'status' => RepaymentStatus::Recorded,
        ]);

        return ['branch' => $branch, 'officer' => $officer, 'product' => $product];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loan(Branch $branch, LoanProduct $product, Merchant $borrower, array $attributes): Loan
    {
        return Loan::factory()->disbursed()->create(array_merge([
            'branch_id' => $branch->id,
            'loan_product_id' => $product->id,
            'merchant_id' => $borrower->id,
            'status' => LoanStatus::Disbursed,
        ], $attributes));
    }

    private function approvedRepayment(Loan $loan, string $amount, string $date, string $principal = '0.00', string $interest = '0.00', string $fee = '0.00'): void
    {
        Repayment::factory()->create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'payment_date' => $date,
            'status' => RepaymentStatus::Approved,
            'approved_at' => $date,
            'allocated_principal' => $principal,
            'allocated_interest' => $interest,
            'allocated_fee' => $fee,
            'allocated_excess' => '0.00',
            'allocated_unallocated' => '0.00',
        ]);
    }

    private function postWriteOffToLedger(Loan $loan, string $amount, int $branchId): void
    {
        $account = LedgerAccount::query()->where('code', StandardAccounts::WRITTEN_OFF_LOANS)->firstOrFail();

        $transactionId = DB::table('journal_transactions')->insertGetId([
            'transaction_reference' => 'NPJ-2026-'.$loan->id,
            'transaction_type' => 'loan_write_off',
            'description' => "Write-off of {$loan->loan_reference}",
            'currency' => 'NGN',
            'transaction_date' => $loan->written_off_at->toDateString(),
            'posting_date' => $loan->written_off_at->toDateString(),
            'status' => 'posted',
            'created_at' => now(),
        ]);

        DB::table('journal_entries')->insert([
            'journal_transaction_id' => $transactionId,
            'ledger_account_id' => $account->id,
            'debit_amount' => $amount,
            'credit_amount' => '0.00',
            'loan_id' => $loan->id,
            'branch_id' => $branchId,
            'created_at' => now(),
        ]);
    }

    private function actingAsAnalyst(array $attributes = []): Staff
    {
        return $this->actingAsStaffWith(
            [Permission::ReportsView],
            array_merge(['access_scope' => AccessScope::Global], $attributes),
        );
    }
}
