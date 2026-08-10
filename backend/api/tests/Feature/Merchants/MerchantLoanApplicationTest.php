<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Businesses\Models\Business;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantLoanApplicationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_can_apply_for_a_loan_against_their_own_business(): void
    {
        $merchant = $this->actingAsMerchant();
        $business = Business::factory()->verified()->create(['merchant_id' => $merchant->id]);
        $product = LoanProduct::factory()->create();

        $response = $this->postJson('/api/v1/merchant/loan-applications', [
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '100000.00',
            'requested_tenor' => 10,
            'purpose' => 'Restocking inventory.',
        ])->assertCreated();

        $this->assertSame('draft', $response->json('data.status'));

        $application = LoanApplication::query()->findOrFail($response->json('data.id'));
        $this->assertSame($merchant->id, $application->merchant_id);
        // created_by is a FK to staff only — left unset for a merchant actor.
        $this->assertNull($application->created_by);
    }

    #[Test]
    public function a_merchant_cannot_apply_using_a_business_they_do_not_own(): void
    {
        $this->actingAsMerchant();
        $otherMerchant = Merchant::factory()->approved()->create();
        $someoneElsesBusiness = Business::factory()->verified()->create(['merchant_id' => $otherMerchant->id]);
        $product = LoanProduct::factory()->create();

        $this->postJson('/api/v1/merchant/loan-applications', [
            'business_id' => $someoneElsesBusiness->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '100000.00',
            'requested_tenor' => 10,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['business_id']]);
    }

    #[Test]
    public function the_merchant_id_cannot_be_supplied_by_the_client(): void
    {
        $merchant = $this->actingAsMerchant();
        $otherMerchant = Merchant::factory()->approved()->create();
        $business = Business::factory()->verified()->create(['merchant_id' => $merchant->id]);
        $product = LoanProduct::factory()->create();

        $response = $this->postJson('/api/v1/merchant/loan-applications', [
            'merchant_id' => $otherMerchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '100000.00',
            'requested_tenor' => 10,
        ])->assertCreated();

        // Ignored — the controller always forces the authenticated merchant,
        // never a client-supplied merchant_id.
        $application = LoanApplication::query()->findOrFail($response->json('data.id'));
        $this->assertSame($merchant->id, $application->merchant_id);
    }

    #[Test]
    public function a_merchant_can_list_and_view_their_own_applications(): void
    {
        $merchant = $this->actingAsMerchant();
        $mine = LoanApplication::factory()->create(['merchant_id' => $merchant->id]);
        LoanApplication::factory()->create(); // someone else's

        $names = collect($this->getJson('/api/v1/merchant/loan-applications')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($names->contains($mine->id));
        $this->assertCount(1, $names);

        $this->getJson("/api/v1/merchant/loan-applications/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);
    }

    #[Test]
    public function a_merchant_cannot_view_another_merchants_application(): void
    {
        $this->actingAsMerchant();
        $someoneElses = LoanApplication::factory()->create();

        $this->getJson("/api/v1/merchant/loan-applications/{$someoneElses->id}")->assertStatus(404);
    }

    #[Test]
    public function a_merchant_can_withdraw_their_own_draft_application(): void
    {
        $merchant = $this->actingAsMerchant();
        $application = LoanApplication::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => LoanApplicationStatus::Draft,
        ]);

        $this->postJson("/api/v1/merchant/loan-applications/{$application->id}/withdraw", [
            'reason' => 'No longer need the extra working capital.',
        ])->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame(LoanApplicationStatus::Withdrawn, $application->fresh()->status);
        // withdrawn_by is a FK to staff only — left unset for a merchant actor.
        $this->assertNull($application->fresh()->withdrawn_by);
    }

    #[Test]
    public function a_merchant_cannot_withdraw_another_merchants_application(): void
    {
        $this->actingAsMerchant();
        $someoneElses = LoanApplication::factory()->create(['status' => LoanApplicationStatus::Draft]);

        $this->postJson("/api/v1/merchant/loan-applications/{$someoneElses->id}/withdraw", [
            'reason' => 'Attempting to withdraw a record I do not own.',
        ])->assertStatus(404);
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/merchant/loan-applications')->assertStatus(401);
    }
}
