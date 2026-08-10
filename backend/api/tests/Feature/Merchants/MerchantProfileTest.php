<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Merchants\Enums\MerchantStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_can_view_their_own_profile(): void
    {
        $merchant = $this->actingAsMerchant();

        $this->getJson('/api/v1/merchant/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $merchant->id)
            ->assertJsonPath('data.merchant_number', $merchant->merchant_number);
    }

    #[Test]
    public function a_merchant_can_update_safe_profile_fields(): void
    {
        $this->actingAsMerchant();

        $this->patchJson('/api/v1/merchant/profile', [
            'phone' => '08099998888',
            'city' => 'Ibadan',
        ])->assertOk()
            ->assertJsonPath('data.phone', '08099998888')
            ->assertJsonPath('data.city', 'Ibadan');
    }

    #[Test]
    public function identity_numbers_and_organisational_fields_cannot_be_set_through_self_service(): void
    {
        $merchant = $this->actingAsMerchant();

        $this->patchJson('/api/v1/merchant/profile', [
            'bvn' => '22233344455',
            'nin' => '84433322211',
            'branch_id' => 999,
            'assigned_officer_id' => 999,
            'onboarding_status' => OnboardingStatus::Approved->value,
            'merchant_status' => MerchantStatus::Suspended->value,
        ])->assertOk();

        $refreshed = $merchant->fresh();

        // None of those fields exist in UpdateMerchantSelfRequest's rules, so
        // they are silently dropped rather than validated and applied.
        $this->assertNull($refreshed->bvn);
        $this->assertNull($refreshed->nin);
        $this->assertSame($merchant->branch_id, $refreshed->branch_id);
        $this->assertSame($merchant->assigned_officer_id, $refreshed->assigned_officer_id);
        $this->assertSame(OnboardingStatus::Approved, $refreshed->onboarding_status);
        $this->assertSame(MerchantStatus::Active, $refreshed->merchant_status);
    }

    #[Test]
    public function an_invalid_phone_number_is_refused(): void
    {
        $this->actingAsMerchant();

        $this->patchJson('/api/v1/merchant/profile', [
            'phone' => '12345',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/merchant/profile')->assertStatus(401);
    }
}
