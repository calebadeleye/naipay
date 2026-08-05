<?php

declare(strict_types=1);

namespace Tests\Feature\LoanApplications;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Branches\Models\Branch;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Enums\Role;
use App\Domains\LoanApplications\Enums\LoanApplicationStatus;
use App\Domains\LoanApplications\Models\Guarantor;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoanApplicationGuarantorTest extends TestCase
{
    use RefreshDatabase;

    // --- Adding and removing --------------------------------------------------

    #[Test]
    public function a_guarantor_can_be_added_to_a_draft_application(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication();

        $response = $this->postJson("/api/v1/admin/loan-applications/{$application->id}/guarantors", [
            'full_name' => 'Ada Eze',
            'phone' => '08031234567',
            'relationship' => 'Sibling',
            'monthly_income' => '150000.00',
        ])->assertCreated();

        $this->assertSame('Ada Eze', $response->json('data.full_name'));
        $this->assertSame(1, $application->guarantors()->count());

        $this->assertSame(
            'loan_application.guarantor_added',
            AuditLog::query()->where('module', 'loan_applications')->latest('id')->value('action'),
        );
    }

    #[Test]
    public function a_guarantor_can_be_listed_and_removed(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication();
        $guarantor = Guarantor::factory()->create(['loan_application_id' => $application->id]);

        $this->getJson("/api/v1/admin/loan-applications/{$application->id}/guarantors")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/admin/loan-applications/{$application->id}/guarantors/{$guarantor->id}")
            ->assertOk();

        $this->assertSame(0, $application->guarantors()->count());
    }

    #[Test]
    public function a_cashier_cannot_add_a_guarantor(): void
    {
        $this->actingAsRole(Role::Cashier);

        $application = $this->draftApplication();

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/guarantors", [
            'full_name' => 'Ada Eze',
            'phone' => '08031234567',
            'relationship' => 'Sibling',
        ])->assertForbidden();
    }

    #[Test]
    public function guarantors_cannot_be_changed_once_an_application_is_approved(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication();
        $application->forceFill(['status' => LoanApplicationStatus::Approved])->save();

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/guarantors", [
            'full_name' => 'Ada Eze',
            'phone' => '08031234567',
            'relationship' => 'Sibling',
        ])->assertStatus(422);
    }

    // --- Submission and approval gating ---------------------------------------

    #[Test]
    public function submission_is_refused_when_the_product_requires_a_guarantor_and_none_is_offered(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication(requiresGuarantor: true);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function submission_succeeds_once_the_minimum_guarantors_are_offered(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication(requiresGuarantor: true);
        Guarantor::factory()->create(['loan_application_id' => $application->id]);

        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    #[Test]
    public function approval_is_refused_if_the_guarantor_is_removed_after_submission(): void
    {
        $application = $this->draftApplication(requiresGuarantor: true);
        $guarantor = Guarantor::factory()->create(['loan_application_id' => $application->id]);

        $application->forceFill([
            'status' => LoanApplicationStatus::Recommended,
            'submitted_at' => now(),
            'expires_at' => now()->addDays(60),
        ])->save();

        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        $this->deleteJson("/api/v1/admin/loan-applications/{$application->id}/guarantors/{$guarantor->id}")
            ->assertOk();

        $this->actingAsRole(Role::CreditManager, ['access_scope' => 'global']);
        $this->postJson("/api/v1/admin/loan-applications/{$application->id}/approve")
            ->assertStatus(422);
    }

    // --- Branch scoping ----------------------------------------------------------

    #[Test]
    public function a_branch_scoped_officer_cannot_reach_another_branchs_guarantors(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $foreign = $this->draftApplication(branch: $kano);

        $this->actingAsRole(Role::LoanOfficer, ['branch_id' => $lagos->id]);

        $this->getJson("/api/v1/admin/loan-applications/{$foreign->id}/guarantors")->assertNotFound();
    }

    // --- Documents -------------------------------------------------------------

    #[Test]
    public function an_identity_document_can_be_attached_to_a_guarantor(): void
    {
        Storage::fake('documents');

        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $application = $this->draftApplication();
        $guarantor = Guarantor::factory()->create(['loan_application_id' => $application->id]);
        $type = DocumentType::factory()->forOwner(DocumentOwnerType::Guarantor)->create();

        $response = $this->post(
            "/api/v1/admin/loan-applications/{$application->id}/guarantors/{$guarantor->id}/documents",
            ['document_type_id' => $type->id, 'file' => $this->pdf()],
            ['Accept' => 'application/json'],
        )->assertCreated();

        $this->assertSame('application/pdf', $response->json('data.mime_type'));

        $document = Document::query()->firstOrFail();
        $this->assertSame(Guarantor::class, $document->documentable_type);
        $this->assertSame($guarantor->id, $document->documentable_id);
    }

    // --- Fixtures ------------------------------------------------------------------

    private function draftApplication(bool $requiresGuarantor = false, ?Branch $branch = null): LoanApplication
    {
        $merchant = Merchant::factory()->approved()->create([
            'merchant_status' => MerchantStatus::Active,
            'branch_id' => $branch?->id,
        ]);

        $business = Business::factory()->verified()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        $product = $requiresGuarantor
            ? LoanProduct::factory()->requiringGuarantors(1)->create()
            : LoanProduct::factory()->create();

        return LoanApplication::factory()->create([
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'branch_id' => $branch?->id ?? $merchant->branch_id,
        ]);
    }

    private function pdf(string $name = 'guarantor-id.pdf'): UploadedFile
    {
        $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'naipay').'.pdf';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
