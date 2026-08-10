<?php

declare(strict_types=1);

namespace Tests\Feature\Merchants;

use App\Domains\Loans\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantLoanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_merchant_can_list_and_view_only_their_own_loans(): void
    {
        $merchant = $this->actingAsMerchant();
        $mine = Loan::factory()->create(['merchant_id' => $merchant->id]);
        Loan::factory()->create(); // someone else's

        $ids = collect($this->getJson('/api/v1/merchant/loans')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertCount(1, $ids);

        $this->getJson("/api/v1/merchant/loans/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $mine->id);
    }

    #[Test]
    public function a_merchant_cannot_view_another_merchants_loan(): void
    {
        $this->actingAsMerchant();
        $someoneElses = Loan::factory()->create();

        $this->getJson("/api/v1/merchant/loans/{$someoneElses->id}")->assertStatus(404);
    }

    #[Test]
    public function a_merchant_can_download_their_own_loans_schedule_pdf(): void
    {
        $merchant = $this->actingAsMerchant();
        $loan = Loan::factory()->disbursed()->create(['merchant_id' => $merchant->id]);

        $response = $this->get("/api/v1/merchant/loans/{$loan->id}/schedule/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_merchant_cannot_download_another_merchants_schedule_pdf(): void
    {
        $this->actingAsMerchant();
        $someoneElses = Loan::factory()->disbursed()->create();

        $this->get("/api/v1/merchant/loans/{$someoneElses->id}/schedule/pdf")->assertStatus(404);
    }

    #[Test]
    public function an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/merchant/loans')->assertStatus(401);
    }
}
