<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Domains\Branches\Models\Branch;
use App\Domains\Documents\Database\Seeders\DocumentTypeSeeder;
use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Enums\DocumentVerificationStatus;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Documents\Services\DocumentService;
use App\Domains\Identity\Enums\Role;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    // --- Upload --------------------------------------------------------------

    #[Test]
    public function a_document_can_be_attached_to_a_merchant(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $response = $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('application/pdf', $response->json('data.mime_type'));
        $this->assertSame('pending', $response->json('data.verification_status'));
        $this->assertSame(1, $response->json('data.version'));

        Storage::disk('documents')->assertExists(Document::query()->firstOrFail()->file_path);
    }

    #[Test]
    public function the_stored_filename_is_generated_not_taken_from_the_upload(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf('../../../etc/passwd.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = Document::query()->firstOrFail();

        // An attacker-supplied name can carry path traversal, a null byte or a
        // second extension, so it is never used to build a path.
        $this->assertStringNotContainsString('..', $document->file_path);
        $this->assertStringNotContainsString('passwd', $document->file_path);
        $this->assertMatchesRegularExpression('#^merchants/\d+/[0-9a-f\-]{36}\.pdf$#', $document->file_path);

        // The original name survives only as a display label.
        $this->assertStringNotContainsString('/', $document->file_name);
    }

    // --- File validation ------------------------------------------------------

    #[Test]
    public function a_file_that_is_not_what_it_claims_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        // A PHP script renamed to .pdf, with a matching content type. Both the
        // extension and the header are attacker-controlled, so the type is
        // determined from the bytes instead.
        $path = tempnam(sys_get_temp_dir(), 'naipay').'.pdf';
        file_put_contents($path, "<?php system(\$_GET['cmd']); ?>");

        $disguised = new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true);

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $disguised,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function an_unaccepted_file_type_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('script.exe', 100),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    #[Test]
    public function an_oversized_file_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('huge.pdf', 20_000),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    #[Test]
    public function images_are_accepted(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->jpeg(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'image/jpeg');
    }

    // --- Required metadata -----------------------------------------------------

    #[Test]
    public function a_type_that_expires_requires_an_expiry_date(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $type = DocumentType::factory()->expiring()->create(['name' => 'Driver’s Licence']);

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['expires_at']]);
    }

    #[Test]
    public function an_already_expired_document_cannot_be_filed(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $type = DocumentType::factory()->expiring()->create();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
            'expires_at' => now()->subDay()->toDateString(),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    #[Test]
    public function a_type_requiring_a_document_number_demands_one(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $type = DocumentType::factory()->needingDocumentNumber()->create();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['document_number']]);
    }

    #[Test]
    public function a_document_type_meant_for_a_business_cannot_be_attached_to_a_merchant(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $businessType = DocumentType::factory()->forOwner(DocumentOwnerType::Business)->create();

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $businessType->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // --- Versioning -------------------------------------------------------------

    #[Test]
    public function replacing_a_document_supersedes_the_previous_version(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $first = $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf('original.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $second = $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf('corrected.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(2, $second->json('data.version'));
        $this->assertSame($first, $second->json('data.supersedes_id'));

        $previous = Document::findOrFail($first);

        // The file compliance actually looked at when they approved a merchant
        // has to remain retrievable, so the old version is kept.
        $this->assertFalse($previous->is_current);
        $this->assertSame(DocumentVerificationStatus::Superseded, $previous->verification_status);
        Storage::disk('documents')->assertExists($previous->file_path);
    }

    #[Test]
    public function the_document_list_shows_only_current_versions_by_default(): void
    {
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        foreach (['v1.pdf', 'v2.pdf'] as $name) {
            $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
                'document_type_id' => $type->id,
                'file' => $this->pdf($name),
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->getJson("/api/v1/admin/merchants/{$merchant->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/admin/merchants/{$merchant->id}/documents?include_superseded=1")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_superseded_version_cannot_be_verified(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $first = $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->postJson("/api/v1/admin/documents/{$first}/verify")->assertStatus(422);
    }

    // --- Verification -------------------------------------------------------------

    #[Test]
    public function a_compliance_officer_can_verify_a_document(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $document = Document::factory()->create();

        $this->postJson("/api/v1/admin/documents/{$document->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.satisfies_requirement', true);
    }

    #[Test]
    public function a_loan_officer_cannot_verify_a_document(): void
    {
        // Uploading and verifying are separate duties: the officer who
        // collected a document must not be the one who attests to it.
        $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);

        $document = Document::factory()->create();

        $this->postJson("/api/v1/admin/documents/{$document->id}/verify")->assertForbidden();
    }

    #[Test]
    public function rejecting_a_document_requires_a_reason(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $document = Document::factory()->create();

        $this->postJson("/api/v1/admin/documents/{$document->id}/reject", ['reason' => 'no'])
            ->assertStatus(422);

        $this->postJson("/api/v1/admin/documents/{$document->id}/reject", [
            'reason' => 'The photograph is illegible and the name does not match.',
        ])->assertOk()->assertJsonPath('data.verification_status', 'rejected');
    }

    #[Test]
    public function an_expired_document_cannot_be_verified(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $document = Document::factory()->create(['expires_at' => now()->subDay()]);

        // A lapsed identity document is evidence of who someone was, not who
        // they are.
        $this->postJson("/api/v1/admin/documents/{$document->id}/verify")->assertStatus(422);
    }

    // --- Expiry ---------------------------------------------------------------

    #[Test]
    public function lapsed_documents_are_expired_by_the_scheduled_sweep(): void
    {
        $current = Document::factory()->verified()->create(['expires_at' => now()->addMonth()]);
        $lapsed = Document::factory()->verified()->create(['expires_at' => now()->subDay()]);

        $count = app(DocumentService::class)->expireLapsedDocuments();

        $this->assertSame(1, $count);
        $this->assertSame(DocumentVerificationStatus::Expired, $lapsed->fresh()->verification_status);
        $this->assertSame(DocumentVerificationStatus::Verified, $current->fresh()->verification_status);

        // A lapsed document silently continuing to satisfy a KYC requirement
        // is how a book ends up unverifiable at audit.
        $this->assertFalse($lapsed->fresh()->satisfiesRequirement());
    }

    #[Test]
    public function documents_approaching_expiry_can_be_listed_for_reminders(): void
    {
        Document::factory()->verified()->create(['expires_at' => now()->addDays(5)]);
        Document::factory()->verified()->create(['expires_at' => now()->addDays(90)]);

        $expiring = app(DocumentService::class)->documentsExpiringWithin(30);

        $this->assertCount(1, $expiring);
    }

    // --- Outstanding requirements ------------------------------------------------

    #[Test]
    public function outstanding_required_documents_are_reported(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);
        $this->seed(DocumentTypeSeeder::class);

        $merchant = Merchant::factory()->create();

        $response = $this->getJson("/api/v1/admin/merchants/{$merchant->id}/documents/outstanding")
            ->assertOk();

        $this->assertFalse($response->json('data.is_complete'));

        $names = collect($response->json('data.outstanding'))->pluck('name');

        $this->assertTrue($names->contains('Passport Photograph'));
        $this->assertTrue($names->contains('National Identification Number Slip'));

        // Every outstanding item is missing, not merely unverified.
        $this->assertTrue(
            collect($response->json('data.outstanding'))->every(fn (array $i): bool => $i['status'] === 'missing')
        );
    }

    #[Test]
    public function an_uploaded_but_unverified_document_still_counts_as_outstanding(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $merchant = Merchant::factory()->create();
        $type = DocumentType::factory()->required()->create(['name' => 'Passport Photograph']);

        Document::factory()->create([
            'documentable_type' => Merchant::class,
            'documentable_id' => $merchant->id,
            'document_type_id' => $type->id,
        ]);

        $outstanding = $this->getJson("/api/v1/admin/merchants/{$merchant->id}/documents/outstanding")
            ->assertOk()
            ->json('data.outstanding');

        // Holding a document is not the same as having verified it.
        $this->assertCount(1, $outstanding);
        $this->assertSame('pending', $outstanding[0]['status']);
    }

    // --- Access control -----------------------------------------------------------

    #[Test]
    public function downloading_a_document_is_audited(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);
        [$merchant, $type] = $this->merchantAndType();

        $id = $this->post("/api/v1/admin/merchants/{$merchant->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->json('data.id');

        $this->get("/api/v1/admin/documents/{$id}/download")->assertOk();

        // Identity documents leaving the system is exactly what a data
        // protection review asks about.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document.downloaded',
            'module' => 'documents',
        ]);
    }

    #[Test]
    public function a_document_response_never_carries_a_file_url(): void
    {
        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $document = Document::factory()->create();

        $body = (string) $this->getJson("/api/v1/admin/documents/{$document->id}")->assertOk()->getContent();

        // A signed or public URL could be forwarded to anyone; identity
        // documents must not be shareable by link.
        $this->assertStringNotContainsString('http', $body);
        $this->assertStringNotContainsString('file_path', $body);
    }

    #[Test]
    public function a_branch_scoped_officer_cannot_reach_another_branchs_documents(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $this->actingAsRole(Role::ComplianceOfficer, [
            'branch_id' => $lagos->id,
            'access_scope' => 'branch',
        ]);

        $foreign = Document::factory()->create([
            'documentable_type' => Merchant::class,
            'documentable_id' => Merchant::factory()->create(['branch_id' => $kano->id])->id,
        ]);

        $this->getJson("/api/v1/admin/documents/{$foreign->id}")->assertNotFound();
        $this->get("/api/v1/admin/documents/{$foreign->id}/download")->assertNotFound();
    }

    /**
     * A genuine one-page PDF. `UploadedFile::fake()->create()` produces a file
     * of the right size full of nulls, which is precisely what the magic-byte
     * check is designed to reject — so tests that should succeed need real
     * bytes.
     */
    private function pdf(string $name = 'passport.pdf'): UploadedFile
    {
        $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'naipay').'.pdf';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        // A 1x1 JPEG.
        $contents = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==', true
        );

        $path = tempnam(sys_get_temp_dir(), 'naipay').'.jpg';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function merchantAndType(): array
    {
        $merchant = Merchant::factory()->create();
        $type = DocumentType::factory()->create(['name' => 'Passport Photograph']);

        return [$merchant, $type];
    }
}
