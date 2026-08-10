<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Businesses\Models\Business;
use App\Domains\Documents\Enums\DocumentOwnerType;
use App\Domains\Documents\Models\Document;
use App\Domains\Documents\Models\DocumentType;
use App\Domains\Identity\Enums\Role;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    #[Test]
    public function a_merchant_can_upload_their_own_document(): void
    {
        $merchant = $this->actingAsMerchant();
        $type = DocumentType::factory()->forOwner(DocumentOwnerType::Merchant)->create();

        $response = $this->post('/api/v1/merchant/documents', [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('pending', $response->json('data.verification_status'));

        $document = Document::query()->firstOrFail();
        $this->assertSame($merchant->id, $document->documentable_id);
        $this->assertSame(Merchant::class, $document->documentable_type);
        $this->assertSame($merchant->id, $document->uploaded_by_merchant_id);
        $this->assertNull($document->uploaded_by);
    }

    #[Test]
    public function a_merchant_can_upload_a_document_for_their_own_business(): void
    {
        $merchant = $this->actingAsMerchant();
        $business = Business::factory()->create(['merchant_id' => $merchant->id]);
        $type = DocumentType::factory()->forOwner(DocumentOwnerType::Business)->create();

        $this->post("/api/v1/merchant/businesses/{$business->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = Document::query()->firstOrFail();
        $this->assertSame($business->id, $document->documentable_id);
        $this->assertSame($merchant->id, $document->uploaded_by_merchant_id);
    }

    #[Test]
    public function a_merchant_cannot_upload_a_document_for_a_business_they_do_not_own(): void
    {
        $this->actingAsMerchant();
        $someoneElsesBusiness = Business::factory()->create();
        $type = DocumentType::factory()->forOwner(DocumentOwnerType::Business)->create();

        $this->post("/api/v1/merchant/businesses/{$someoneElsesBusiness->id}/documents", [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertStatus(404);
    }

    #[Test]
    public function a_merchant_uploaded_document_can_still_be_verified_by_staff(): void
    {
        $merchant = $this->actingAsMerchant();

        $type = DocumentType::factory()->forOwner(DocumentOwnerType::Merchant)->create();

        $this->post('/api/v1/merchant/documents', [
            'document_type_id' => $type->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = Document::query()->firstOrFail();

        $this->actingAsRole(Role::ComplianceOfficer, ['access_scope' => 'global']);

        $this->postJson("/api/v1/admin/documents/{$document->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    private function pdf(string $name = 'utility-bill.pdf'): UploadedFile
    {
        $contents = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'naipay').'.pdf';
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
