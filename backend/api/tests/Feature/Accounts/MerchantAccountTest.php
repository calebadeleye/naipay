<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domains\Accounts\Models\MerchantAccount;
use App\Domains\Accounts\Services\MerchantAccountService;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Models\BusinessCategory;
use App\Domains\Identity\Enums\Role;
use App\Domains\Merchants\Enums\KycStatus;
use App\Domains\Merchants\Enums\OnboardingStatus;
use App\Domains\Merchants\Models\Merchant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MerchantAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_account_is_opened_when_a_merchant_is_approved(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->approvableMerchant();

        $this->assertNull($merchant->account);

        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertOk();

        $account = $merchant->fresh()->account;

        $this->assertNotNull($account);
        $this->assertSame($merchant->fullName(), $account->account_name);
        $this->assertSame('NGN', $account->currency);
        $this->assertSame('active', $account->status->value);
    }

    #[Test]
    public function the_account_number_is_exactly_ten_digits(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->approvableMerchant();
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertOk();

        // Matches the Nigerian NUBAN length merchants already recognise.
        $this->assertMatchesRegularExpression(
            '/^\d{10}$/',
            $merchant->fresh()->account->account_number,
        );
    }

    #[Test]
    public function account_numbers_are_random_rather_than_sequential(): void
    {
        $numbers = [];

        for ($i = 0; $i < 25; $i++) {
            $numbers[] = app(MerchantAccountService::class)
                ->openFor(Merchant::factory()->create())
                ->account_number;
        }

        $this->assertCount(25, array_unique($numbers));

        // A sequential scheme would disclose the size of the book and make
        // neighbouring accounts guessable — these numbers end up on transfer
        // instructions and receipts.
        $sorted = $numbers;
        sort($sorted);
        $this->assertNotSame($sorted, $numbers, 'Account numbers appear to be sequential.');

        $consecutive = 0;

        for ($i = 1; $i < count($numbers); $i++) {
            if ((int) $numbers[$i] - (int) $numbers[$i - 1] === 1) {
                $consecutive++;
            }
        }

        $this->assertSame(0, $consecutive);
    }

    #[Test]
    public function the_number_is_a_fixed_width_string(): void
    {
        $account = app(MerchantAccountService::class)->openFor(Merchant::factory()->create());

        // An account number is an identifier, not a quantity: stored and read
        // as a string so a leading zero is never lost.
        $this->assertIsString($account->account_number);
        $this->assertSame(10, strlen($account->account_number));
    }

    #[Test]
    public function opening_an_account_is_idempotent(): void
    {
        $merchant = Merchant::factory()->create();
        $service = app(MerchantAccountService::class);

        $first = $service->openFor($merchant);
        $second = $service->openFor($merchant);

        // A replayed approval must not leave a merchant holding two account
        // numbers.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MerchantAccount::query()->where('merchant_id', $merchant->id)->count());
    }

    #[Test]
    public function account_numbers_are_unique_at_the_database_level(): void
    {
        $account = app(MerchantAccountService::class)->openFor(Merchant::factory()->create());

        $this->expectException(QueryException::class);

        $duplicate = new MerchantAccount([
            'merchant_id' => Merchant::factory()->create()->id,
            'account_name' => 'Duplicate',
        ]);

        $duplicate->forceFill(['account_number' => $account->account_number])->save();
    }

    #[Test]
    public function the_account_number_is_shown_on_the_merchant_record(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->approvableMerchant();
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertOk();

        $response = $this->getJson("/api/v1/admin/merchants/{$merchant->id}")->assertOk();

        $this->assertMatchesRegularExpression('/^\d{10}$/', $response->json('data.account.account_number'));

        // Grouped for reading aloud; the stored value stays bare so a
        // copy-and-paste still matches.
        $this->assertMatchesRegularExpression(
            '/^\d{4} \d{4} \d{2}$/',
            $response->json('data.account.account_number_formatted'),
        );
    }

    #[Test]
    public function opening_an_account_is_audited(): void
    {
        $this->actingAsRole(Role::OperationsManager, ['access_scope' => 'global']);

        $merchant = $this->approvableMerchant();
        $this->postJson("/api/v1/admin/merchants/{$merchant->id}/approve")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.opened',
            'module' => 'accounts',
        ]);
    }

    private function approvableMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create([
            'onboarding_status' => OnboardingStatus::PendingApproval,
            'kyc_status' => KycStatus::Verified,
        ]);

        Business::factory()->create([
            'merchant_id' => $merchant->id,
            'business_category_id' => BusinessCategory::factory()->create()->id,
        ]);

        return $merchant->fresh();
    }
}
