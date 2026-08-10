<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Businesses\Models\Business;
use App\Domains\Identity\Enums\Role;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for AuditLogger's actor-widening (Staff|Merchant),
 * added for the merchant self-service portal — confirms a merchant action is
 * attributed correctly and that staff-originated entries are unaffected.
 */
final class AuditLogMerchantActorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_originated_action_is_attributed_to_the_merchant_not_staff(): void
    {
        $merchant = $this->actingAsMerchant();
        $business = Business::factory()->verified()->create(['merchant_id' => $merchant->id]);
        $product = LoanProduct::factory()->create();

        $this->postJson('/api/v1/merchant/loan-applications', [
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '100000.00',
            'requested_tenor' => 10,
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'loan_application.created')->firstOrFail();

        $this->assertSame('merchant', $entry->actor_type);
        $this->assertSame($merchant->id, $entry->merchant_id);
        $this->assertNull($entry->staff_id);
        $this->assertSame($merchant->fullName(), $entry->actor_name);
    }

    #[Test]
    public function a_staff_originated_action_is_unaffected_by_the_actor_widening(): void
    {
        $actor = $this->actingAsRole(Role::LoanOfficer, ['access_scope' => 'global']);
        $merchant = Merchant::factory()->approved()->create();
        $business = Business::factory()->verified()->create(['merchant_id' => $merchant->id]);
        $product = LoanProduct::factory()->create();

        $this->postJson('/api/v1/admin/loan-applications', [
            'merchant_id' => $merchant->id,
            'business_id' => $business->id,
            'loan_product_id' => $product->id,
            'requested_amount' => '100000.00',
            'requested_tenor' => 10,
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'loan_application.created')->firstOrFail();

        $this->assertSame('staff', $entry->actor_type);
        $this->assertSame($actor->id, $entry->staff_id);
        $this->assertNull($entry->merchant_id);
    }
}
