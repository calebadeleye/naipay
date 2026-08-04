<?php

declare(strict_types=1);

namespace Tests\Feature\Businesses;

use App\Domains\Branches\Models\Branch;
use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Enums\BusinessType;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BusinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_business_is_created_against_a_merchant(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $category = BusinessCategory::factory()->create();

        $response = $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Grace Stores',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $category->id,
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^NPB-\d{6}$/', $response->json('data.business_number'));
        $this->assertSame('inactive', $response->json('data.status'));
        $this->assertSame('unverified', $response->json('data.verification_status'));
    }

    // --- Category discipline ------------------------------------------------

    #[Test]
    public function a_business_cannot_be_filed_under_a_withdrawn_category(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $withdrawn = BusinessCategory::factory()->inactive()->create();

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Test Business',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $withdrawn->id,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['business_category_id']]);
    }

    #[Test]
    public function a_subcategory_must_belong_to_the_chosen_category(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $agriculture = BusinessCategory::factory()->create(['name' => 'Agriculture']);
        $beauty = BusinessCategory::factory()->create(['name' => 'Beauty']);
        $hairdressing = BusinessCategory::factory()->childOf($beauty)->create(['name' => 'Hairdressing']);

        // Without this check a business could be filed under
        // "Agriculture › Hairdressing", which no report would make sense of.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Mismatched Business',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $agriculture->id,
            'business_subcategory_id' => $hairdressing->id,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['business_subcategory_id']]);
    }

    #[Test]
    public function a_subcategory_cannot_be_used_as_the_top_level_category(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $parent = BusinessCategory::factory()->create();
        $child = BusinessCategory::factory()->childOf($parent)->create();

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Test Business',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $child->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_category_must_come_from_the_vocabulary(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();

        // There is no way to supply a category as free text; an unknown id is
        // the closest an officer could get, and it is refused.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Test Business',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => 999999,
        ])->assertStatus(422);
    }

    // --- Monetary figures ---------------------------------------------------

    #[Test]
    public function declared_figures_round_trip_exactly(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $category = BusinessCategory::factory()->create();

        $response = $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
            'business_name' => 'Precise Traders',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $category->id,
            'estimated_monthly_revenue' => '1250000.55',
            'estimated_monthly_expenses' => '830000.30',
        ])->assertCreated();

        // These feed repayment-capacity assessment; a float would give a
        // different affordability answer on different runs.
        $this->assertSame('1250000.55', $response->json('data.estimated_monthly_revenue.amount'));
        $this->assertSame('830000.30', $response->json('data.estimated_monthly_expenses.amount'));
        $this->assertSame('420000.25', $response->json('data.declared_monthly_surplus.amount'));
        $this->assertSame('₦420,000.25', $response->json('data.declared_monthly_surplus.formatted'));
    }

    #[Test]
    public function a_malformed_monetary_figure_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $category = BusinessCategory::factory()->create();

        foreach (['1,250,000.00', '100.005', 'abc'] as $invalid) {
            $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
                'business_name' => 'Test Business',
                'business_type' => 'sole_proprietorship',
                'business_category_id' => $category->id,
                'estimated_monthly_revenue' => $invalid,
            ])->assertStatus(422);
        }
    }

    #[Test]
    public function surplus_is_null_when_either_side_is_unknown(): void
    {
        $business = Business::factory()->create([
            'estimated_monthly_revenue' => '500000.00',
            'estimated_monthly_expenses' => null,
        ]);

        // "We did not ask" is a different answer from "there is none"; assuming
        // zero would silently overstate affordability.
        $this->assertNull($business->fresh()->declaredMonthlySurplus());
    }

    #[Test]
    public function a_negative_surplus_is_represented_rather_than_clamped(): void
    {
        $business = Business::factory()->create([
            'estimated_monthly_revenue' => '300000.00',
            'estimated_monthly_expenses' => '450000.00',
        ]);

        $surplus = $business->fresh()->declaredMonthlySurplus();

        // A business trading at a loss is exactly what credit assessment needs
        // to see.
        $this->assertTrue($surplus->isNegative());
        $this->assertSame('-150000.00', $surplus->toDecimalString());
    }

    // --- Verification -------------------------------------------------------

    #[Test]
    public function verification_activates_a_business(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $business = Business::factory()->create();

        $this->postJson("/api/v1/admin/businesses/{$business->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($business->fresh()->isOperational());
    }

    #[Test]
    public function an_unverified_business_cannot_be_activated_directly(): void
    {
        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        $business = Business::factory()->create();

        // Otherwise verification could be bypassed by setting the status.
        $this->postJson("/api/v1/admin/businesses/{$business->id}/status", [
            'status' => 'active',
            'reason' => 'Attempting to activate without a field verification.',
        ])->assertStatus(422);

        $this->assertSame(BusinessStatus::Inactive, $business->fresh()->status);
    }

    #[Test]
    public function a_loan_officer_cannot_verify_a_business_they_registered(): void
    {
        // businesses.approve is held by Operations, Branch Manager and above —
        // not by the officer who does the onboarding.
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $business = Business::factory()->create();

        $this->postJson("/api/v1/admin/businesses/{$business->id}/verify")->assertForbidden();
    }

    // --- Registration expectations ------------------------------------------

    #[Test]
    public function a_limited_company_is_flagged_when_it_has_no_cac_number(): void
    {
        $business = Business::factory()->create([
            'business_type' => BusinessType::LimitedLiabilityCompany,
            'cac_registration_number' => null,
        ]);

        $this->assertTrue($business->fresh()->hasOutstandingRegistration());
    }

    #[Test]
    public function an_informal_business_is_never_flagged_for_registration(): void
    {
        $business = Business::factory()->create([
            'business_type' => BusinessType::InformalBusiness,
            'cac_registration_number' => null,
        ]);

        // Informal businesses are a large share of the microfinance book and
        // are legitimately unregistered; demanding a certificate would block
        // exactly the merchants this product exists to serve.
        $this->assertFalse($business->fresh()->hasOutstandingRegistration());
    }

    #[Test]
    public function the_business_type_options_report_which_expect_registration(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        $options = $this->getJson('/api/v1/admin/businesses/types')->assertOk()->json('data');

        $this->assertCount(8, $options);

        $llc = collect($options)->firstWhere('value', 'limited_liability_company');
        $informal = collect($options)->firstWhere('value', 'informal_business');

        $this->assertTrue($llc['requires_cac']);
        $this->assertFalse($informal['requires_cac']);
    }

    // --- Future business connections ----------------------------------------

    #[Test]
    public function business_connection_features_are_off_and_cannot_be_switched_on(): void
    {
        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        $business = Business::factory()->create()->fresh();

        $this->assertFalse($business->public_profile_enabled);
        $this->assertFalse($business->accepts_business_connections);

        // Reserved for a later phase. A public profile must never appear
        // without the merchant's explicit consent, and the surest way to
        // guarantee that now is for no endpoint to be able to set it.
        $this->patchJson("/api/v1/admin/businesses/{$business->id}", [
            'public_profile_enabled' => true,
            'accepts_business_connections' => true,
            'business_visibility' => 'public',
        ])->assertOk();

        $refreshed = $business->fresh();

        $this->assertFalse($refreshed->public_profile_enabled);
        $this->assertFalse($refreshed->accepts_business_connections);
        $this->assertSame('private', $refreshed->business_visibility);
    }

    // --- Scoping --------------------------------------------------------------

    #[Test]
    public function businesses_are_scoped_through_their_merchants_branch(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        $local = Business::factory()->create([
            'merchant_id' => Merchant::factory()->create(['branch_id' => $lagos->id])->id,
            'business_name' => 'Lagos Business',
        ]);
        $foreign = Business::factory()->create([
            'merchant_id' => Merchant::factory()->create(['branch_id' => $kano->id])->id,
            'business_name' => 'Kano Business',
        ]);

        // Businesses carry no branch of their own; the scope runs through the
        // merchant relationship rather than duplicating branch_id and risking
        // the two drifting apart.
        $names = collect($this->getJson('/api/v1/admin/businesses')->assertOk()->json('data'))
            ->pluck('business_name');

        $this->assertTrue($names->contains('Lagos Business'));
        $this->assertFalse($names->contains('Kano Business'));

        $this->getJson("/api/v1/admin/businesses/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/businesses/{$local->id}")->assertOk();
    }

    #[Test]
    public function businesses_can_be_filtered_by_declared_revenue(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        Business::factory()->create(['business_name' => 'Small Trader', 'estimated_monthly_revenue' => '150000.00']);
        Business::factory()->create(['business_name' => 'Large Trader', 'estimated_monthly_revenue' => '4500000.00']);

        $names = collect(
            $this->getJson('/api/v1/admin/businesses?estimated_monthly_revenue_min=1000000.00')
                ->assertOk()
                ->json('data')
        )->pluck('business_name');

        $this->assertTrue($names->contains('Large Trader'));
        $this->assertFalse($names->contains('Small Trader'));
    }

    #[Test]
    public function money_comparisons_in_filters_are_exact(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        Business::factory()->create(['business_name' => 'Exact Boundary', 'estimated_monthly_revenue' => '1000000.00']);
        Business::factory()->create(['business_name' => 'One Kobo Under', 'estimated_monthly_revenue' => '999999.99']);

        $names = collect(
            $this->getJson('/api/v1/admin/businesses?estimated_monthly_revenue_min=1000000.00')
                ->assertOk()
                ->json('data')
        )->pluck('business_name');

        $this->assertTrue($names->contains('Exact Boundary'));
        $this->assertFalse($names->contains('One Kobo Under'));

        $this->assertTrue(
            Money::fromDecimal('1000000.00')->greaterThan(Money::fromDecimal('999999.99'))
        );
    }
}
