<?php

declare(strict_types=1);

namespace Tests\Feature\LoanApplications;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoanApplicationTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation --------------------------------------------------------------

    #[Test]
    public function a_loan_officer_can_create_an_application_as_a_draft(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        [$merchant, $business, $product] = $this->eligibleFixtures();

        $response = $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
            'purpose' => 'Inventory restock.',
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^NPA-\d{4}-\d{6}$/', $response->json('data.application_number'));
        $this->assertSame('draft', $response->json('data.status'));
    }

    #[Test]
    public function an_application_defaults_to_the_merchants_branch(): void
    {
        $branch = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        [$merchant, $business, $product] = $this->eligibleFixtures($branch);

        $response = $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
        ])->assertCreated();

        $this->assertSame($branch->id, LoanApplication::query()->firstOrFail()->branch_id);
        $this->assertSame($branch->id, $response->json('data.branch.id'));
    }

    #[Test]
    public function a_cashier_cannot_create_an_application(): void
    {
        $this->actingAsRole(Role::Cashier);

        [$merchant, $business, $product] = $this->eligibleFixtures();

        $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
        ])->assertForbidden();
    }

    #[Test]
    public function a_business_belonging_to_a_different_merchant_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        [$merchant, , $product] = $this->eligibleFixtures();
        $otherMerchant = Merchant::factory()->approved()->create(['merchant_status' => MerchantStatus::Active]);
        $foreignBusiness = Business::factory()->verified()->create([
            'merchant_id' => $otherMerchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $foreignBusiness->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['business_id']]);
    }

    #[Test]
    public function a_requested_amount_outside_the_products_limits_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        [$merchant, $business, $product] = $this->eligibleFixtures();

        $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => $product->maximum_amount->plus($product->maximum_amount)->toDecimalString(),
            'requested_tenor' => 20,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['requested_amount']]);
    }

    // --- The workflow ------------------------------------------------------------

    #[Test]
    public function the_full_workflow_runs_end_to_end(): void
    {
        $branch = Branch::factory()->create();
        $officer = $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $branch->id]);

        [$merchant, $business, $product] = $this->eligibleFixtures($branch);

        // 1. The loan officer creates the application.
        $applicationId = $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '150000.00',
            'requested_tenor' => 20,
            'purpose' => 'Inventory restock ahead of the festive season.',
        ])->assertCreated()->json('data.id');

        // 2. Submitted for assessment.
        $this->postJson("/api/v1/admin/loan-applications/{$applicationId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        // 3. A credit officer assesses it.
        $this->actingAsRole(Role::CreditOfficer, ['branch_id' => $branch->id]);

        $this->postJson("/api/v1/admin/loan-applications/{$applicationId}/assess", [
            'notes' => 'Bank statements confirm consistent daily turnover.',
        ])->assertOk()->assertJsonPath('data.status', 'under_assessment');

        // 4. And recommends it.
        $this->postJson("/api/v1/admin/loan-applications/{$applicationId}/recommend", [
            'notes' => 'Recommended for approval at the requested amount.',
        ])->assertOk()->assertJsonPath('data.status', 'recommended');

        // 5. A credit manager approves — a different person from the creator.
        $this->actingAsRole(Role::CreditManager, ['branch_id' => $branch->id]);

        $approved = $this->postJson("/api/v1/admin/loan-applications/{$applicationId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('150000.00', $approved->json('data.approved.amount.amount'));
        $this->assertSame(20, $approved->json('data.approved.tenor'));

        $actions = AuditLog::query()->where('module', 'loan_applications')->pluck('action')->all();

        $this->assertEqualsCanonicalizing([
            'loan_application.created',
            'loan_application.submitted',
            'loan_application.assessed',
            'loan_application.recommended',
            'loan_application.approved',
        ], $actions);

        $this->assertNotSame($officer->id, LoanApplication::find($applicationId)->approved_by);
    }

    #[Test]
    public function an_approval_may_override_the_requested_amount_and_tenor(): void
    {
        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Recommended);

        $response = $this->postJson("/api/v1/admin/loan-applications/{$application->id}/approve", [
            'approved_amount' => '100000.00',
            'approved_tenor' => 15,
        ])->assertOk();

        $this->assertSame('100000.00', $response->json('data.approved.amount.amount'));
        $this->assertSame(15, $response->json('data.approved.tenor'));
    }

    #[Test]
    public function the_creating_officer_can_now_approve_their_own_application(): void
    {
        $approver = $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Recommended, createdBy: $approver->id);

        // Self-approval is no longer restricted — see
        // docs/roles-and-permissions.md.
        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/approve")
            ->assertOk();

        $this->assertSame(LoanApplicationStatus::Approved, $application->fresh()->status);
    }

    #[Test]
    public function a_different_credit_manager_may_approve(): void
    {
        $creator = Staff::factory()->create();

        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Recommended, createdBy: $creator->id);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/approve")->assertOk();

        $this->assertSame(LoanApplicationStatus::Approved, $application->fresh()->status);
    }

    #[Test]
    public function an_application_cannot_be_submitted_for_a_merchant_who_cannot_yet_borrow(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        // A freshly created merchant is still in draft onboarding.
        $merchant = Merchant::factory()->create();
        $business = Business::factory()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        $application = LoanApplication::factory()->create([
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
        ]);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function statuses_cannot_be_skipped(): void
    {
        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Draft);

        // Draft straight to approved would bypass assessment and
        // recommendation entirely.
        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/approve")->assertStatus(422);

        $this->assertSame(LoanApplicationStatus::Draft, $application->fresh()->status);
    }

    #[Test]
    public function a_rejected_application_can_be_returned_to_draft_and_reworked(): void
    {
        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Recommended);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/reject", [
            'reason' => 'Insufficient trading history to support this facility.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/return-to-draft", [
            'reason' => 'Additional bank statements have been requested from the merchant.',
        ])->assertOk()->assertJsonPath('data.status', 'draft');

        $this->assertTrue($application->fresh()->status->isEditable());
    }

    #[Test]
    public function a_pending_application_can_be_withdrawn(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Submitted);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/withdraw", [
            'reason' => 'The merchant no longer wishes to proceed at this time.',
        ])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    #[Test]
    public function workflow_actions_requiring_a_reason_refuse_a_token_one(): void
    {
        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);

        $application = $this->applicationReadyFor(LoanApplicationStatus::Recommended);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/reject", ['reason' => 'no'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['reason']]);
    }

    #[Test]
    public function an_editable_application_can_be_updated_but_a_submitted_one_cannot(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $draft = $this->applicationReadyFor(LoanApplicationStatus::Draft);

        $this->patchJson("/api/v1/admin/loan-applications/{$draft->id}", [
            'requested_amount' => '200000.00',
        ])->assertOk()->assertJsonPath('data.requested.amount.amount', '200000.00');

        $submitted = $this->applicationReadyFor(LoanApplicationStatus::Submitted);

        $this->patchJson("/api/v1/admin/loan-applications/{$submitted->id}", [
            'requested_amount' => '200000.00',
        ])->assertStatus(422);
    }

    // --- Branch scoping ----------------------------------------------------------

    #[Test]
    public function a_branch_scoped_officer_cannot_open_another_branchs_application(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        $foreign = $this->applicationReadyFor(LoanApplicationStatus::Draft, branchId: $kano->id);

        $this->getJson("/api/v1/admin/loan-applications/{$foreign->id}")->assertNotFound();
    }

    #[Test]
    public function a_branch_scoped_officer_sees_only_their_own_branch_in_the_list(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        $mine = $this->applicationReadyFor(LoanApplicationStatus::Draft, branchId: $lagos->id);
        $this->applicationReadyFor(LoanApplicationStatus::Draft, branchId: $kano->id);

        $numbers = collect($this->getJson('/api/v1/admin/loan-applications')->assertOk()->json('data'))
            ->pluck('application_number');

        $this->assertTrue($numbers->contains($mine->application_number));
        $this->assertCount(1, $numbers);
    }

    // --- Expiry sweep --------------------------------------------------------------

    #[Test]
    public function a_lapsed_pending_application_is_swept_to_expired(): void
    {
        $lapsed = $this->applicationReadyFor(LoanApplicationStatus::Submitted);
        $lapsed->forceFill(['expires_at' => now()->subDay()])->save();

        $notYetDue = $this->applicationReadyFor(LoanApplicationStatus::Recommended);
        $notYetDue->forceFill(['expires_at' => now()->addDay()])->save();

        $this->artisan('naipay:loan-applications:expire-lapsed')->assertSuccessful();

        $this->assertSame(LoanApplicationStatus::Expired, $lapsed->fresh()->status);
        $this->assertSame(LoanApplicationStatus::Recommended, $notYetDue->fresh()->status);
    }

    #[Test]
    public function an_approved_application_is_never_swept_regardless_of_its_expiry_date(): void
    {
        $approved = $this->applicationReadyFor(LoanApplicationStatus::Approved);
        $approved->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('naipay:loan-applications:expire-lapsed')->assertSuccessful();

        $this->assertSame(LoanApplicationStatus::Approved, $approved->fresh()->status);
    }

    // --- Fixtures ------------------------------------------------------------------

    /**
     * @return array{0: Merchant, 1: Business, 2: LoanProduct}
     */
    private function eligibleFixtures(?Branch $branch = null): array
    {
        $merchant = Merchant::factory()->approved()->create([
            'merchant_status' => MerchantStatus::Active,
            'branch_id' => $branch?->id,
        ]);

        $business = Business::factory()->verified()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        $product = LoanProduct::factory()->create();

        return [$merchant, $business, $product];
    }

    /**
     * An application, with an eligible merchant and business, sitting at the
     * given status.
     */
    private function applicationReadyFor(
        LoanApplicationStatus $status,
        ?int $createdBy = null,
        ?int $branchId = null,
    ): LoanApplication {
        [$merchant, $business, $product] = $this->eligibleFixtures();

        $isPending = in_array($status, [
            LoanApplicationStatus::Submitted,
            LoanApplicationStatus::UnderAssessment,
            LoanApplicationStatus::Recommended,
        ], true);

        $application = LoanApplication::factory()->create([
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'branch_id' => $branchId ?? $merchant->branch_id,
            'status' => $status,
            'created_by' => $createdBy,
            'submitted_at' => $status !== LoanApplicationStatus::Draft ? now() : null,
            'expires_at' => $isPending ? now()->addDays(60) : null,
        ]);

        return $application->fresh();
    }
}
