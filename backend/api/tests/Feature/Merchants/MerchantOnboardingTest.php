<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation ----------------------------------------------------------

    #[Test]
    public function a_loan_officer_can_create_a_merchant_as_a_draft(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $response = $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Grace',
            'last_name' => 'Okafor',
            'phone' => '08031234567',
            'state' => 'Lagos',
        ])->assertCreated();

        // A reference is allocated at creation, not at approval: the record
        // sits in draft for days while documents are gathered, and staff need
        // something to quote in the meantime.
        $this->assertMatchesRegularExpression('/^NPM-\d{6}$/', $response->json('data.merchant_number'));
        $this->assertSame('draft', $response->json('data.onboarding_status'));
        $this->assertFalse($response->json('data.can_borrow'));
    }

    #[Test]
    public function a_merchant_defaults_to_the_creating_officers_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $branch->id]);

        $response = $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Ibrahim', 'last_name' => 'Musa', 'phone' => '08031234567',
        ])->assertCreated();

        // Without this, a branch-scoped officer would create a record they
        // immediately could not see.
        $this->assertSame($branch->id, Merchant::query()->firstOrFail()->branch_id);
        $this->assertSame($branch->id, $response->json('data.branch.id'));
    }

    #[Test]
    public function a_cashier_cannot_create_a_merchant(): void
    {
        $this->actingAsRole(Role::Cashier);

        $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Test', 'last_name' => 'Person', 'phone' => '08031234567',
        ])->assertForbidden();
    }

    // --- The workflow ------------------------------------------------------

    #[Test]
    public function the_full_onboarding_workflow_runs_end_to_end(): void
    {
        $branch = Branch::factory()->create();
        $officer = $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $branch->id]);

        // 1. The officer creates the merchant.
        $merchantId = $this->postJson('/api/v1/admin/merchants', [
            'first_name' => 'Blessing', 'last_name' => 'Eze',
            'phone' => '08031234567', 'bvn' => '22123456714',
        ])->assertCreated()->json('data.id');

        // 2. And adds their business, chosen from the controlled vocabulary.
        $category = BusinessCategory::factory()->create();

        $this->postJson("/api/v1/admin/merchants/{$merchantId}/businesses", [
            'business_name' => 'Blessing Enterprises',
            'business_type' => 'sole_proprietorship',
            'business_category_id' => $category->id,
            'estimated_monthly_revenue' => '850000.00',
            'estimated_monthly_expenses' => '520000.00',
        ])->assertCreated();

        // 3. Submitted for verification.
        $this->postJson("/api/v1/admin/merchants/{$merchantId}/submit")
            ->assertOk()
            ->assertJsonPath('data.onboarding_status', 'submitted');

        // 4. Compliance verifies KYC.
        $this->actingAsRole(Role::ComplianceOfficer, ['branch_id' => $branch->id]);

        $this->postJson("/api/v1/admin/merchants/{$merchantId}/verify")
            ->assertOk()
            ->assertJsonPath('data.onboarding_status', 'pending_approval')
            ->assertJsonPath('data.kyc_status', 'verified');

        // 5. A different officer approves — the creator cannot.
        $this->actingAsRole(Role::OperationsManager, ['branch_id' => $branch->id]);

        $approved = $this->postJson("/api/v1/admin/merchants/{$merchantId}/approve")
            ->assertOk()
            ->assertJsonPath('data.onboarding_status', 'approved')
            ->assertJsonPath('data.merchant_status', 'active');

        $this->assertTrue($approved->json('data.can_borrow'));

        // Every step is on the record.
        $actions = AuditLog::query()->where('module', 'merchants')->pluck('action')->all();

        $this->assertEqualsCanonicalizing([
            'merchant.created',
            'merchant.submitted',
            'merchant.verification_started',
            'merchant.verified',
            'merchant.approved',
        ], $actions);

        $this->assertNotSame($officer->id, Merchant::find($merchantId)->approved_by);
    }

    #[Test]
    public function the_creating_officer_can_now_approve_their_own_merchant(): void
    {
        $officer = $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->merchantReadyFor(OnboardingStatus::PendingApproval, createdBy: $officer->id);

        // Self-approval is no longer restricted — see
        // docs/roles-and-permissions.md.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")
            ->assertOk();

        $this->assertSame(OnboardingStatus::Approved, $merchant->fresh()->onboarding_status);
    }

    #[Test]
    public function a_different_officer_may_approve(): void
    {
        $creator = Staff::factory()->create();

        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->merchantReadyFor(OnboardingStatus::PendingApproval, createdBy: $creator->id);

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertOk();

        $this->assertSame(OnboardingStatus::Approved, $merchant->fresh()->onboarding_status);
    }

    #[Test]
    public function a_merchant_without_a_business_cannot_be_submitted(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();

        // The credit product is advanced against trading activity; a merchant
        // with no business has nothing to lend against.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function a_merchant_cannot_be_approved_without_verified_kyc(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create([
            'onboarding_status' => OnboardingStatus::PendingApproval,
            'kyc_status' => KycStatus::Pending,
        ]);
        Business::factory()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertStatus(422);
    }

    #[Test]
    public function statuses_cannot_be_skipped(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->merchantReadyFor(OnboardingStatus::Draft);

        // Draft straight to approved would bypass verification entirely.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertStatus(422);

        $this->assertSame(OnboardingStatus::Draft, $merchant->fresh()->onboarding_status);
    }

    #[Test]
    public function a_rejected_merchant_can_be_returned_to_draft_and_reworked(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->merchantReadyFor(OnboardingStatus::PendingApproval);

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", [
            'reason' => 'Identity documents do not match the application.',
        ])->assertOk()->assertJsonPath('data.onboarding_status', 'rejected');

        // Reworked rather than re-keyed from scratch.
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/return-to-draft", [
            'reason' => 'Merchant has supplied corrected documents.',
        ])->assertOk()->assertJsonPath('data.onboarding_status', 'draft');

        $this->assertTrue($merchant->fresh()->onboarding_status->isEditable());
    }

    #[Test]
    public function an_approved_merchant_cannot_be_edited_without_returning_to_draft(): void
    {
        $this->actingAsRole(Role::SuperAdministrator, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->approved()->create();

        $this->patchJson("/api/v1/admin/merchants/{$merchant->id}", ['city' => 'Ibadan'])
            ->assertStatus(422);
    }

    #[Test]
    public function an_approved_merchant_can_be_suspended_and_reinstated(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->approved()->create();

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/suspend", [
            'reason' => 'Suspected fraudulent activity under investigation.',
        ])->assertOk()->assertJsonPath('data.merchant_status', 'suspended');

        $this->assertFalse($merchant->fresh()->canBorrow());

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/reinstate", [
            'reason' => 'Investigation concluded with no findings.',
        ])->assertOk()->assertJsonPath('data.merchant_status', 'active');

        $this->assertTrue($merchant->fresh()->canBorrow());
    }

    #[Test]
    public function workflow_actions_requiring_a_reason_refuse_a_token_one(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->merchantReadyFor(OnboardingStatus::PendingApproval);

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/reject", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    // --- Branch scoping ----------------------------------------------------

    #[Test]
    public function a_branch_scoped_officer_cannot_open_another_branchs_merchant(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        $foreign = Merchant::factory()->create(['branch_id' => $kano->id]);

        // Route model binding resolves by id regardless of branch, so the scope
        // has to be re-applied on the detail endpoint. A correctly filtered
        // list means nothing if the detail route hands over any record by id.
        $this->getJson("/api/v1/admin/merchants/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function a_branch_scoped_officer_sees_only_their_own_branch_in_the_list(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        Merchant::factory()->create(['branch_id' => $lagos->id, 'last_name' => 'Lagos Merchant']);
        Merchant::factory()->create(['branch_id' => $kano->id, 'last_name' => 'Kano Merchant']);

        $names = collect($this->getJson('/api/v1/admin/merchants')->assertOk()->json('data'))
            ->pluck('last_name');

        $this->assertTrue($names->contains('Lagos Merchant'));
        $this->assertFalse($names->contains('Kano Merchant'));
    }

    // --- Merchant and business relationship --------------------------------

    #[Test]
    public function a_merchant_may_hold_several_businesses(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $category = BusinessCategory::factory()->create();

        foreach (['First Enterprise', 'Second Enterprise'] as $name) {
            $this->postJson("/api/v1/admin/merchants/{$merchant->id}/businesses", [
                'business_name' => $name,
                'business_type' => 'sole_proprietorship',
                'business_category_id' => $category->id,
            ])->assertCreated();
        }

        // One-to-many from the outset, so adding the second needs no migration.
        $this->assertSame(2, $merchant->businesses()->count());
    }

    #[Test]
    public function the_merchant_status_and_onboarding_status_are_tracked_separately(): void
    {
        $merchant = Merchant::factory()->approved()->create();

        // Onboarding describes how the record got here; merchant status
        // describes what it may do now. A suspended merchant is still an
        // approved one whose trading has been stopped.
        $merchant->forceFill(['merchant_status' => MerchantStatus::Suspended])->save();

        $refreshed = $merchant->fresh();

        $this->assertSame(OnboardingStatus::Approved, $refreshed->onboarding_status);
        $this->assertSame(MerchantStatus::Suspended, $refreshed->merchant_status);
        $this->assertFalse($refreshed->canBorrow());
    }

    /**
     * A merchant with one business, sitting at the given status.
     */
    private function merchantReadyFor(OnboardingStatus $status, ?int $createdBy = null): Merchant
    {
        $merchant = Merchant::factory()->create([
            'onboarding_status' => $status,
            'created_by' => $createdBy,
            'kyc_status' => $status === OnboardingStatus::PendingApproval
                ? KycStatus::Verified
                : KycStatus::NotStarted,
        ]);

        Business::factory()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        return $merchant->fresh();
    }
}
