<?php

declare(strict_types=1);

namespace Tests\Feature\LoanProducts;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Role;
use App\Domains\LoanProducts\Database\Seeders\LoanProductSeeder;
use App\Domains\LoanProducts\Models\LoanProduct;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LoanProductTest extends TestCase
{
    use RefreshDatabase;

    // --- The seeded packages -------------------------------------------------

    #[Test]
    public function the_three_packages_are_seeded_with_their_agreed_pricing(): void
    {
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();
        $weekly = LoanProduct::query()->where('code', 'NPD-WEEKLY')->firstOrFail();
        $monthly = LoanProduct::query()->where('code', 'NPD-MONTHLY')->firstOrFail();

        $this->assertSame('20.0000', $daily->interest_rate);
        $this->assertSame('40.0000', $weekly->interest_rate);
        $this->assertSame('40.0000', $monthly->interest_rate);

        $this->assertSame('daily', $daily->repayment_frequency->value);
        $this->assertSame('weekly', $weekly->repayment_frequency->value);
        $this->assertSame('monthly', $monthly->repayment_frequency->value);

        // Flat: charged once on the amount borrowed, unchanged by the term.
        foreach ([$daily, $weekly, $monthly] as $product) {
            $this->assertSame('flat', $product->interest_method->value);
            $this->assertFalse($product->interest_method->variesWithTerm());
        }
    }

    #[Test]
    public function only_the_daily_package_skips_weekends(): void
    {
        $this->seed(LoanProductSeeder::class);

        $this->assertTrue(
            LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail()
                ->repayment_frequency->skipsWeekends()
        );

        $this->assertFalse(
            LoanProduct::query()->where('code', 'NPD-WEEKLY')->firstOrFail()
                ->repayment_frequency->skipsWeekends()
        );
    }

    #[Test]
    public function tenor_is_a_range_rather_than_a_fixed_number(): void
    {
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        // The officer sets the actual term per loan after agreeing the package
        // with the merchant.
        $this->assertLessThan($daily->maximum_tenor, $daily->minimum_tenor);
        $this->assertTrue($daily->acceptsTenor(20));
        $this->assertTrue($daily->acceptsTenor(120));
        $this->assertFalse($daily->acceptsTenor(5));
        $this->assertFalse($daily->acceptsTenor(200));
    }

    #[Test]
    public function a_reseed_never_overwrites_pricing_an_administrator_changed(): void
    {
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();
        $daily->forceFill(['interest_rate' => '18.0000'])->save();

        $this->seed(LoanProductSeeder::class);

        // Pricing is the business's decision; a deploy must not quietly
        // reverse it.
        $this->assertSame('18.0000', $daily->fresh()->interest_rate);
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $this->seed(LoanProductSeeder::class);
        $this->seed(LoanProductSeeder::class);

        $this->assertSame(3, LoanProduct::query()->count());
    }

    // --- The calculation preview ---------------------------------------------

    #[Test]
    public function a_hundred_thousand_naira_daily_loan_repays_one_hundred_and_twenty_thousand(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $response = $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '100000.00',
            'tenor' => 20,
            'disbursement_date' => '2026-03-02',
        ])->assertOk();

        $this->assertSame('20000.00', $response->json('data.summary.total_interest.amount'));
        $this->assertSame('120000.00', $response->json('data.summary.total_payable.amount'));
        $this->assertSame('₦120,000.00', $response->json('data.summary.total_payable.formatted'));
        $this->assertSame(20, $response->json('data.summary.instalment_count'));
    }

    #[Test]
    public function a_one_million_naira_weekly_loan_repays_one_point_four_million(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $weekly = LoanProduct::query()->where('code', 'NPD-WEEKLY')->firstOrFail();

        $response = $this->postJson("/api/v1/admin/loan-products/{$weekly->id}/preview", [
            'amount' => '1000000.00',
            'tenor' => 12,
        ])->assertOk();

        $this->assertSame('1400000.00', $response->json('data.summary.total_payable.amount'));
    }

    #[Test]
    public function the_preview_returns_a_full_dated_schedule(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $response = $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '100000.00',
            'tenor' => 10,
            'disbursement_date' => '2026-03-02',
        ])->assertOk();

        $schedule = $response->json('data.schedule');

        $this->assertCount(10, $schedule);
        $this->assertSame('2026-03-03', $schedule[0]['due_date']);
        $this->assertSame('10000.00', $schedule[0]['principal_due']);
        $this->assertSame('2000.00', $schedule[0]['interest_due']);
        $this->assertSame('12000.00', $schedule[0]['total_due']);

        // The merchant is told exactly what they repay and on which dates
        // before agreeing to anything.
        $this->assertSame('0.00', $schedule[9]['closing_principal']);
    }

    #[Test]
    public function a_daily_preview_never_schedules_a_weekend_payment(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $schedule = $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '500000.00',
            'tenor' => 30,
            'disbursement_date' => '2026-03-02',
        ])->assertOk()->json('data.schedule');

        foreach ($schedule as $row) {
            $day = Carbon::parse($row['due_date']);

            $this->assertFalse(
                $day->isSaturday() || $day->isSunday(),
                "Instalment {$row['installment_number']} falls on {$day->format('l')}.",
            );
        }
    }

    #[Test]
    public function the_preview_reports_the_net_amount_reaching_the_merchant(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        // A 2% processing fee taken upfront.
        $product = LoanProduct::factory()->withProcessingFee('2.00')->create();

        $response = $this->postJson("/api/v1/admin/loan-products/{$product->id}/preview", [
            'amount' => '100000.00',
            'tenor' => 20,
        ])->assertOk();

        $this->assertSame('2000.00', $response->json('data.summary.total_fees.amount'));
        $this->assertSame('98000.00', $response->json('data.summary.net_disbursement.amount'));
        $this->assertSame('122000.00', $response->json('data.summary.total_payable.amount'));
    }

    #[Test]
    public function an_amount_outside_the_product_limits_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '5000.00', // Below the ₦10,000 minimum.
            'tenor' => 20,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);

        $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '9000000.00',
            'tenor' => 20,
        ])->assertStatus(422);
    }

    #[Test]
    public function a_tenor_outside_the_product_limits_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $this->postJson("/api/v1/admin/loan-products/{$daily->id}/preview", [
            'amount' => '100000.00',
            'tenor' => 500,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['tenor']]);
    }

    #[Test]
    public function a_malformed_amount_is_refused(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $product = LoanProduct::factory()->create();

        foreach (['1,000.00', '100.005', 'abc'] as $invalid) {
            $this->postJson("/api/v1/admin/loan-products/{$product->id}/preview", [
                'amount' => $invalid,
                'tenor' => 20,
            ])->assertStatus(422);
        }
    }

    // --- Administration --------------------------------------------------------

    #[Test]
    public function a_credit_manager_can_add_a_new_package(): void
    {
        $this->actingAsRole(Role::CreditManager);

        // New packages are added without a deployment — the whole point of
        // products being data.
        $this->postJson('/api/v1/admin/loan-products', [
            'code' => 'NPD-FORTNIGHT',
            'name' => 'Fortnightly Repayment',
            'minimum_amount' => '25000.00',
            'maximum_amount' => '2000000.00',
            'minimum_tenor' => 2,
            'maximum_tenor' => 26,
            'tenor_unit' => 'weeks',
            'interest_method' => 'flat',
            'interest_rate' => '30.0000',
            'repayment_frequency' => 'weekly',
        ])->assertCreated()->assertJsonPath('data.code', 'NPD-FORTNIGHT');
    }

    #[Test]
    public function a_loan_officer_cannot_change_pricing(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $product = LoanProduct::factory()->create();

        $this->patchJson("/api/v1/admin/loan-products/{$product->id}", [
            'interest_rate' => '5.0000',
        ])->assertForbidden();

        $this->assertSame('20.0000', $product->fresh()->interest_rate);
    }

    #[Test]
    public function a_rate_change_is_audited(): void
    {
        $actor = $this->actingAsRole(Role::CreditManager);
        $product = LoanProduct::factory()->create();

        $this->patchJson("/api/v1/admin/loan-products/{$product->id}", [
            'interest_rate' => '25.0000',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'loan_product.updated',
            'staff_id' => $actor->id,
        ]);

        $entry = AuditLog::query()
            ->where('action', 'loan_product.updated')
            ->firstOrFail();

        $this->assertSame('20.0000', $entry->old_values['interest_rate']);
        $this->assertSame('25.0000', $entry->new_values['interest_rate']);
    }

    #[Test]
    public function a_maximum_below_the_minimum_is_refused(): void
    {
        $this->actingAsRole(Role::CreditManager);

        $this->postJson('/api/v1/admin/loan-products', [
            'code' => 'NPD-BROKEN',
            'name' => 'Broken Product',
            'minimum_amount' => '100000.00',
            'maximum_amount' => '50000.00',
            'minimum_tenor' => 10,
            'maximum_tenor' => 5,
            'tenor_unit' => 'days',
            'interest_method' => 'flat',
            'interest_rate' => '20.0000',
            'repayment_frequency' => 'daily',
        ])->assertStatus(422)
            ->assertJsonStructure(['errors' => ['maximum_amount', 'maximum_tenor']]);
    }

    #[Test]
    public function a_product_is_retired_rather_than_deleted(): void
    {
        $this->actingAsRole(Role::CreditManager);
        $product = LoanProduct::factory()->create();

        $this->deleteJson("/api/v1/admin/loan-products/{$product->id}")->assertStatus(405);

        $this->postJson("/api/v1/admin/loan-products/{$product->id}/status", [
            'status' => 'retired',
            'reason' => 'Replaced by the revised daily package.',
        ])->assertOk()->assertJsonPath('data.is_active', false);

        // Loans booked under it must keep pointing at the terms they were sold
        // on, so the row stays.
        $this->assertDatabaseHas('loan_products', ['id' => $product->id, 'deleted_at' => null]);
    }

    #[Test]
    public function retired_products_do_not_appear_in_the_picker(): void
    {
        $this->actingAsRole(Role::LoanOfficer);

        LoanProduct::factory()->create(['name' => 'Available Package']);
        LoanProduct::factory()->retired()->create(['name' => 'Withdrawn Package']);

        $labels = collect($this->getJson('/api/v1/admin/loan-products/options')->assertOk()->json('data'))
            ->pluck('label');

        $this->assertTrue($labels->contains('Available Package'));
        $this->assertFalse($labels->contains('Withdrawn Package'));
    }

    #[Test]
    public function the_picker_carries_everything_needed_to_configure_a_loan(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $options = $this->getJson('/api/v1/admin/loan-products/options')->assertOk()->json('data');

        $daily = collect($options)->firstWhere('code', 'NPD-DAILY');

        foreach (['value', 'label', 'summary', 'frequency', 'minimum_amount', 'maximum_amount', 'minimum_tenor', 'maximum_tenor', 'default_tenor', 'tenor_unit'] as $key) {
            $this->assertArrayHasKey($key, $daily);
        }

        $this->assertSame('20% flat rate, repaid every working day over 10 to 120 days.', $daily['summary']);
    }

    #[Test]
    public function the_interest_rate_is_returned_as_an_exact_string(): void
    {
        $this->actingAsRole(Role::LoanOfficer);
        $this->seed(LoanProductSeeder::class);

        $daily = LoanProduct::query()->where('code', 'NPD-DAILY')->firstOrFail();

        $response = $this->getJson("/api/v1/admin/loan-products/{$daily->id}")->assertOk();

        // A float would change what a merchant owes; the rate multiplies
        // principal, so it crosses the wire as a decimal string.
        $this->assertIsString($response->json('data.interest.rate'));
        $this->assertSame('20.0000', $response->json('data.interest.rate'));
    }

    #[Test]
    public function product_limits_are_compared_exactly(): void
    {
        $product = LoanProduct::factory()->create([
            'minimum_amount' => '10000.00',
            'maximum_amount' => '5000000.00',
        ]);

        $this->assertTrue($product->acceptsAmount(Money::fromDecimal('10000.00')));
        $this->assertFalse($product->acceptsAmount(Money::fromDecimal('9999.99')));
        $this->assertTrue($product->acceptsAmount(Money::fromDecimal('5000000.00')));
        $this->assertFalse($product->acceptsAmount(Money::fromDecimal('5000000.01')));
    }
}
