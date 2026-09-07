<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantRepaymentSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_the_outstanding_balance_across_disbursed_loans_only(): void
    {
        $merchant = $this->actingAsMerchant();

        Loan::factory()->disbursed()->create([
            'merchant_id' => $merchant->id,
            'outstanding_principal' => '50000.00',
            'outstanding_interest' => '5000.00',
            'outstanding_fees' => '0.00',
        ]);

        // Not disbursed yet — should not count toward the balance.
        Loan::factory()->create(['merchant_id' => $merchant->id, 'status' => LoanStatus::PendingApproval]);

        $response = $this->getJson('/api/v1/merchant/repayments/summary')->assertOk();

        $this->assertSame('55000.00', $response->json('data.outstanding_balance.amount'));
        $this->assertSame(1, $response->json('data.active_loan_count'));
    }

    #[Test]
    public function it_shows_the_default_collection_bank_account(): void
    {
        $this->actingAsMerchant();

        BankAccount::factory()->forCollection()->approved()->create([
            'bank_name' => 'Zenith Bank',
            'account_number' => '1234567890',
            'is_default_collection_account' => true,
        ]);

        // A non-default one should not be picked.
        BankAccount::factory()->create(['purposes' => [BankAccountPurpose::LoanDisbursement->value]]);

        $response = $this->getJson('/api/v1/merchant/repayments/summary')->assertOk();

        $this->assertSame('Zenith Bank', $response->json('data.pay_into.bank_name'));
        $this->assertSame('1234567890', $response->json('data.pay_into.account_number'));
    }

    #[Test]
    public function another_merchants_loans_are_never_included(): void
    {
        $this->actingAsMerchant();
        Loan::factory()->disbursed()->create(['outstanding_principal' => '999999.00']);

        $response = $this->getJson('/api/v1/merchant/repayments/summary')->assertOk();

        $this->assertSame('0.00', $response->json('data.outstanding_balance.amount'));
        $this->assertSame(0, $response->json('data.active_loan_count'));
    }
}
