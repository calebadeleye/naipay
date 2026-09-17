<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Loans\Models\Loan;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantLoanSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_merchant_profile_reports_total_paid_and_outstanding_across_disbursed_loans(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->approved()->create(['merchant_status' => MerchantStatus::Active]);

        // Partially repaid: 100,000 of 150,000 principal and all 30,000
        // interest paid, 50,000 principal still outstanding.
        Loan::factory()->disbursed()->create([
            'merchant_id' => $merchant->id,
            'outstanding_principal' => '50000.00',
            'outstanding_interest' => '0.00',
            'outstanding_fees' => '0.00',
        ]);

        // Not yet disbursed — must not count toward the summary at all.
        Loan::factory()->create(['merchant_id' => $merchant->id]);

        $response = $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();

        $this->assertSame('130000.00', $response->json('data.loan_summary.total_paid.amount'));
        $this->assertSame('50000.00', $response->json('data.loan_summary.total_outstanding.amount'));
        // 130,000 paid of 180,000 total payable.
        $this->assertSame(72.22, $response->json('data.loan_summary.percent_paid'));
        $this->assertSame(1, $response->json('data.loan_summary.disbursed_loan_count'));
        $this->assertSame(0, $response->json('data.loan_summary.fully_paid_loan_count'));
        $this->assertTrue($response->json('data.loan_summary.has_active_loan'));
        $this->assertFalse($response->json('data.loan_summary.all_loans_fully_paid'));
    }

    #[Test]
    public function a_fully_repaid_loan_is_flagged_on_both_the_loan_and_the_merchant_summary(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->approved()->create(['merchant_status' => MerchantStatus::Active]);

        $loan = Loan::factory()->disbursed()->create([
            'merchant_id' => $merchant->id,
            'outstanding_principal' => '0.00',
            'outstanding_interest' => '0.00',
            'outstanding_fees' => '0.00',
        ]);

        $loanResponse = $this->getJson("/api/v1/admin/loans/{$loan->id}")->assertOk();
        $this->assertTrue($loanResponse->json('data.is_fully_paid'));
        $this->assertSame('180000.00', $loanResponse->json('data.payments.total_paid.amount'));

        $merchantResponse = $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();
        $this->assertTrue($merchantResponse->json('data.loan_summary.all_loans_fully_paid'));
        $this->assertFalse($merchantResponse->json('data.loan_summary.has_active_loan'));
        $this->assertSame(1, $merchantResponse->json('data.loan_summary.fully_paid_loan_count'));
        $this->assertSame(100, $merchantResponse->json('data.loan_summary.percent_paid'));
    }

    #[Test]
    public function a_merchant_who_has_fully_paid_off_a_loan_can_still_apply_for_another_one(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->approved()->create(['merchant_status' => MerchantStatus::Active]);

        Loan::factory()->disbursed()->create([
            'merchant_id' => $merchant->id,
            'outstanding_principal' => '0.00',
            'outstanding_interest' => '0.00',
            'outstanding_fees' => '0.00',
        ]);

        $business = Business::factory()->verified()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);
        $product = LoanProduct::factory()->create();

        // Eligibility (Merchant::canBorrow()) is unaffected by past loans —
        // completed or otherwise — so a second application must go through.
        $this->assertTrue($merchant->fresh()->canBorrow());

        $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
            'purpose' => 'Second loan after fully repaying the first.',
        ])->assertCreated();
    }
}
