<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Enums\VerificationStatus;
use App\Domains\Businesses\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantBusinessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_can_list_and_view_only_their_own_businesses(): void
    {
        $merchant = $this->actingAsMerchant();
        $mine = Business::factory()->create(['merchant_id' => $merchant->id]);
        Business::factory()->create(); // someone else's

        $ids = collect($this->getJson('/api/v1/merchant/businesses')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);

        $this->getJson("/api/v1/merchant/businesses/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);
    }

    #[Test]
    public function a_merchant_cannot_view_another_merchants_business(): void
    {
        $this->actingAsMerchant();
        $someoneElses = Business::factory()->create();

        $this->getJson("/api/v1/merchant/businesses/{$someoneElses->id}")->assertStatus(404);
    }

    #[Test]
    public function a_merchant_can_update_safe_fields_on_their_own_business(): void
    {
        $merchant = $this->actingAsMerchant();
        $business = Business::factory()->create(['merchant_id' => $merchant->id]);

        $this->patchJson("/api/v1/merchant/businesses/{$business->id}", [
            'business_phone' => '08011112222',
            'business_description' => 'A small retail shop selling household goods.',
        ])->assertOk()->assertJsonPath('data.business_phone', '08011112222');
    }

    #[Test]
    public function a_merchant_cannot_update_another_merchants_business(): void
    {
        $this->actingAsMerchant();
        $someoneElses = Business::factory()->create();

        $this->patchJson("/api/v1/merchant/businesses/{$someoneElses->id}", [
            'business_phone' => '08011112222',
        ])->assertStatus(404);
    }

    #[Test]
    public function status_and_verification_status_cannot_be_set_through_self_service(): void
    {
        $merchant = $this->actingAsMerchant();
        $business = Business::factory()->create(['merchant_id' => $merchant->id]);

        $this->patchJson("/api/v1/merchant/businesses/{$business->id}", [
            'status' => BusinessStatus::Active->value,
            'verification_status' => VerificationStatus::Verified->value,
        ])->assertOk();

        $refreshed = $business->fresh();

        $this->assertSame(BusinessStatus::Inactive, $refreshed->status);
        $this->assertSame(VerificationStatus::Unverified, $refreshed->verification_status);
    }
}
