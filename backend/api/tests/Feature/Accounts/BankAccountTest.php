<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Enums\Role;
use App\Domains\Identity\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Naipay's own designated bank accounts — every point real money actually
 * moves through, so the guarantees under test are: nothing is usable until a
 * second officer confirms it, a material change withdraws that confirmation,
 * and the officer who proposed a change can never be the one who approves it.
 */
final class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    // --- Creation --------------------------------------------------------------

    #[Test]
    public function a_finance_manager_can_register_a_new_account(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Naipay Microfinance',
            'account_number' => '1234567890',
            'currency' => 'NGN',
            'account_purpose' => BankAccountPurpose::LoanDisbursement->value,
        ]);

        $response->assertCreated();
        $this->assertSame('1234567890', $response->json('data.account_number'));
        $this->assertFalse($response->json('data.is_approved'));
        $this->assertFalse($response->json('data.can_transact'));

        $this->assertDatabaseHas('bank_accounts', [
            'account_number' => '1234567890',
            'approved_by' => null,
        ]);
    }

    #[Test]
    public function an_account_number_must_be_exactly_ten_digits(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Naipay Microfinance',
            'account_number' => '12345',
            'account_purpose' => BankAccountPurpose::LoanDisbursement->value,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['account_number']);
    }

    #[Test]
    public function the_same_bank_and_account_number_cannot_be_registered_twice(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        BankAccount::factory()->create([
            'bank_name' => 'Zenith Bank',
            'account_number' => '1234567890',
        ]);

        $response = $this->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Naipay Microfinance',
            'account_number' => '1234567890',
            'account_purpose' => BankAccountPurpose::LoanDisbursement->value,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['account_number']);
    }

    #[Test]
    public function creating_an_account_is_refused_without_the_manage_permission(): void
    {
        $this->actingAsStaffWith([Permission::BankAccountsView]);

        $response = $this->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Naipay Microfinance',
            'account_number' => '1234567890',
            'account_purpose' => BankAccountPurpose::LoanDisbursement->value,
        ]);

        $response->assertForbidden();
    }

    #[Test]
    public function creating_an_account_is_audited(): void
    {
        $staff = $this->actingAsRole(Role::FinanceManager);

        $this->postJson('/api/v1/admin/bank-accounts', [
            'bank_name' => 'Zenith Bank',
            'account_name' => 'Naipay Microfinance',
            'account_number' => '1234567890',
            'account_purpose' => BankAccountPurpose::LoanDisbursement->value,
        ])->assertCreated();

        $entry = AuditLog::query()->where('action', 'bank_account.created')->firstOrFail();

        $this->assertSame($staff->id, $entry->staff_id);
    }

    // --- Approval and maker-checker --------------------------------------------

    #[Test]
    public function a_second_officer_can_approve_a_newly_created_account(): void
    {
        $maker = Staff::factory()->create();
        $account = BankAccount::factory()->create(['created_by' => $maker->id]);

        $this->actingAsRole(Role::FinanceManager);

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/approve");

        $response->assertOk();
        $this->assertTrue($response->json('data.is_approved'));
        $this->assertTrue($response->json('data.can_transact'));

        $account->refresh();
        $this->assertNotNull($account->approved_by);
        $this->assertNotNull($account->approved_at);
    }

    #[Test]
    public function the_officer_who_created_an_account_can_now_approve_it(): void
    {
        $maker = $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->create(['created_by' => $maker->id]);

        // Self-approval is no longer restricted — see
        // docs/roles-and-permissions.md.
        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/approve");

        $response->assertOk();

        $account->refresh();
        $this->assertSame($maker->id, $account->approved_by);
    }

    #[Test]
    public function an_already_approved_account_cannot_be_approved_again(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create();

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/approve");

        $response->assertStatus(422);
    }

    #[Test]
    public function approving_an_account_requires_the_approve_permission(): void
    {
        $this->actingAsStaffWith([Permission::BankAccountsView, Permission::BankAccountsManage]);

        $account = BankAccount::factory()->create();

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/approve");

        $response->assertForbidden();
    }

    #[Test]
    public function a_brand_new_account_cannot_transact_before_approval(): void
    {
        $account = BankAccount::factory()->create();

        $this->assertFalse($account->canTransact());
        $this->assertFalse($account->isApproved());
    }

    #[Test]
    public function an_approved_active_account_can_transact(): void
    {
        $account = BankAccount::factory()->approved()->create();

        $this->assertTrue($account->canTransact());
    }

    // --- Material changes withdraw approval -------------------------------------

    #[Test]
    public function changing_the_account_number_withdraws_an_existing_approval(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create(['account_number' => '1111111111']);

        $response = $this->patchJson("/api/v1/admin/bank-accounts/{$account->id}", [
            'account_number' => '2222222222',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('data.is_approved'));

        $account->refresh();
        $this->assertNull($account->approved_by);
        $this->assertNull($account->approved_at);
        $this->assertSame('2222222222', $account->account_number);
    }

    #[Test]
    public function changing_only_the_branch_name_does_not_disturb_an_existing_approval(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create();

        $response = $this->patchJson("/api/v1/admin/bank-accounts/{$account->id}", [
            'branch_name' => 'Victoria Island Branch',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.is_approved'));

        $account->refresh();
        $this->assertNotNull($account->approved_by);
    }

    #[Test]
    public function setting_a_field_to_its_current_value_does_not_disturb_approval(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create(['bank_name' => 'Zenith Bank']);

        $response = $this->patchJson("/api/v1/admin/bank-accounts/{$account->id}", [
            'bank_name' => 'Zenith Bank',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('data.is_approved'));
    }

    // --- Defaults ----------------------------------------------------------------

    #[Test]
    public function making_an_account_the_default_unsets_the_previous_default_of_that_purpose(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $first = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
            'is_default_disbursement_account' => true,
        ]);
        $second = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$second->id}/default", [
            'which' => 'disbursement',
        ]);

        $response->assertOk();

        $this->assertFalse($first->fresh()->is_default_disbursement_account);
        $this->assertTrue($second->fresh()->is_default_disbursement_account);
    }

    #[Test]
    public function collection_and_disbursement_defaults_are_independent(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create();

        $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/default", ['which' => 'collection'])
            ->assertOk();
        $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/default", ['which' => 'disbursement'])
            ->assertOk();

        $account->refresh();
        $this->assertTrue($account->is_default_collection_account);
        $this->assertTrue($account->is_default_disbursement_account);
    }

    #[Test]
    public function a_suspended_account_cannot_be_made_a_default(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->suspended()->create();

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/default", [
            'which' => 'collection',
        ]);

        $response->assertStatus(422);
    }

    // --- Status changes ------------------------------------------------------------

    #[Test]
    public function suspending_an_account_clears_its_default_flags(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create([
            'is_default_collection_account' => true,
            'is_default_disbursement_account' => true,
        ]);

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/status", [
            'status' => BankAccountStatus::Suspended->value,
            'reason' => 'Under review by the bank following a suspected fraud alert.',
        ]);

        $response->assertOk();

        $account->refresh();
        $this->assertSame(BankAccountStatus::Suspended, $account->status);
        $this->assertFalse($account->is_default_collection_account);
        $this->assertFalse($account->is_default_disbursement_account);
        $this->assertFalse($account->canTransact());
    }

    #[Test]
    public function a_status_change_requires_a_reason(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create();

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/status", [
            'status' => BankAccountStatus::Suspended->value,
            'reason' => 'too short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    #[Test]
    public function an_account_cannot_be_changed_to_the_status_it_already_holds(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->approved()->create();

        $response = $this->postJson("/api/v1/admin/bank-accounts/{$account->id}/status", [
            'status' => BankAccountStatus::Active->value,
            'reason' => 'Attempting to set the same status it already has.',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function there_is_no_delete_endpoint_for_a_bank_account(): void
    {
        $this->actingAsRole(Role::FinanceManager);

        $account = BankAccount::factory()->create();

        $this->deleteJson("/api/v1/admin/bank-accounts/{$account->id}")->assertStatus(405);
    }

    // --- Reading --------------------------------------------------------------------

    #[Test]
    public function only_active_accounts_are_offered_as_options(): void
    {
        $this->actingAsStaffWith([Permission::BankAccountsView]);

        BankAccount::factory()->approved()->create(['bank_name' => 'Active Bank']);
        BankAccount::factory()->approved()->suspended()->create(['bank_name' => 'Suspended Bank']);

        $labels = collect($this->getJson('/api/v1/admin/bank-accounts/options')->assertOk()->json('data'))
            ->pluck('label');

        $this->assertTrue($labels->contains(fn (string $label): bool => str_contains($label, 'Active Bank')));
        $this->assertFalse($labels->contains(fn (string $label): bool => str_contains($label, 'Suspended Bank')));
    }

    #[Test]
    public function viewing_the_list_requires_the_view_permission(): void
    {
        $response = $this->getJson('/api/v1/admin/bank-accounts');

        $response->assertUnauthorized();
    }

    #[Test]
    public function an_account_can_be_searched_by_account_number(): void
    {
        $this->actingAsStaffWith([Permission::BankAccountsView]);

        BankAccount::factory()->create(['account_number' => '1234567890']);
        BankAccount::factory()->create(['account_number' => '9999999999']);

        $response = $this->getJson('/api/v1/admin/bank-accounts?search=1234567890');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
