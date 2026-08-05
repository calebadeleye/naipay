<?php

declare(strict_types=1);

namespace Tests\Feature\Receipts;

use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Receipts\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    #[Test]
    public function a_receipt_can_be_retrieved(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsView]);
        $receipt = Receipt::factory()->create();

        $response = $this->getJson("/api/v1/admin/receipts/{$receipt->id}");

        $response->assertOk();
        $this->assertSame($receipt->receipt_number, $response->json('data.receipt_number'));
    }

    #[Test]
    public function the_receipt_list_can_be_searched_by_reference(): void
    {
        // The list is scoped by branch via the underlying loan, the same as
        // every other operational list — global scope here so the test is
        // about search, not branch visibility.
        $this->actingAsStaffWith([Permission::RepaymentsView], ['access_scope' => AccessScope::Global]);
        $match = Receipt::factory()->create(['receipt_number' => 'NPT-2026-000042']);
        Receipt::factory()->create(['receipt_number' => 'NPT-2026-000099']);

        $response = $this->getJson('/api/v1/admin/receipts?search=000042');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($match->id, $response->json('data.0.id'));
    }

    #[Test]
    public function viewing_receipts_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/receipts')->assertUnauthorized();
    }

    #[Test]
    public function there_is_no_create_or_delete_endpoint_for_a_receipt(): void
    {
        $this->actingAsStaffWith([Permission::RepaymentsView]);
        $receipt = Receipt::factory()->create();

        $this->postJson('/api/v1/admin/receipts', [])->assertStatus(405);
        $this->deleteJson("/api/v1/admin/receipts/{$receipt->id}")->assertStatus(405);
    }
}
