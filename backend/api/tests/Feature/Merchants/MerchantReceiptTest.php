<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Receipts\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantReceiptTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_can_list_and_view_only_their_own_receipts(): void
    {
        $merchant = $this->actingAsMerchant();
        $mine = Receipt::factory()->create(['merchant_id' => $merchant->id]);
        Receipt::factory()->create(); // someone else's

        $ids = collect($this->getJson('/api/v1/merchant/receipts')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);

        $this->getJson("/api/v1/merchant/receipts/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);
    }

    #[Test]
    public function a_merchant_cannot_view_another_merchants_receipt(): void
    {
        $this->actingAsMerchant();
        $someoneElses = Receipt::factory()->create();

        $this->getJson("/api/v1/merchant/receipts/{$someoneElses->id}")->assertStatus(404);
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/merchant/receipts')->assertStatus(401);
    }
}
