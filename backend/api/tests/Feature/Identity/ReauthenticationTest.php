<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domains\Accounts\Enums\BankAccountPurpose;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Database\Seeders\ChartOfAccountsSeeder;
use App\Domains\Loans\Enums\LoanStatus;
use App\Domains\Loans\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Reauthentication at the point of action.
 *
 * A handful of operations — named in
 * naipay.security.reauthentication_required_operations — must not be
 * reachable on nothing more than an already-unlocked session; the actor must
 * prove their password (and 2FA code, where enabled) again, no matter how
 * recently they signed in. These tests go through the real login pipeline
 * rather than Sanctum::actingAs(), because the whole point is to prove a
 * genuine, database-backed token — the only kind a real request ever has —
 * is actually checked.
 */
final class ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PASSWORD = 'Correct-Horse-Battery-Staple-9!';

    #[Test]
    public function a_privileged_operation_is_refused_without_recent_reauthentication(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$staff, $token] = $this->realSignedInStaff(Permission::LoansDisburse);

        $loan = Loan::factory()->pendingDisbursement()->create();
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->tokenRequest($token, 'POST', "/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(428);
        $this->assertSame(LoanStatus::PendingDisbursement, $loan->fresh()->status);
    }

    #[Test]
    public function reauthenticating_then_retrying_succeeds(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$staff, $token] = $this->realSignedInStaff(Permission::LoansDisburse);

        $loan = Loan::factory()->pendingDisbursement()->create();
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/reauthenticate', [
            'password' => self::PASSWORD,
            'code' => $this->currentOtp(),
        ])->assertOk();

        $response = $this->tokenRequest($token, 'POST', "/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertOk();
        $this->assertSame(LoanStatus::Disbursed, $loan->fresh()->status);
    }

    #[Test]
    public function reauthentication_is_refused_with_the_wrong_password(): void
    {
        [, $token] = $this->realSignedInStaff(Permission::LoansDisburse);

        $response = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/reauthenticate', [
            'password' => 'definitely-not-it',
            'code' => $this->currentOtp(),
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function reauthentication_is_refused_with_the_wrong_two_factor_code(): void
    {
        [, $token] = $this->realSignedInStaff(Permission::LoansDisburse);

        $response = $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/reauthenticate', [
            'password' => self::PASSWORD,
            'code' => '000000',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function a_reauthentication_older_than_the_configured_window_no_longer_counts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$staff, $token] = $this->realSignedInStaff(Permission::LoansDisburse);

        $this->tokenRequest($token, 'POST', '/api/v1/admin/auth/reauthenticate', [
            'password' => self::PASSWORD,
            'code' => $this->currentOtp(),
        ])->assertOk();

        // Simulate the window having elapsed.
        DB::table('personal_access_tokens')->update([
            'reauthenticated_at' => now()->subMinutes(20),
        ]);

        $loan = Loan::factory()->pendingDisbursement()->create();
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        $response = $this->tokenRequest($token, 'POST', "/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(428);
    }

    #[Test]
    public function reauthenticating_only_marks_the_token_used_for_the_request(): void
    {
        [$staff] = $this->realSignedInStaff(Permission::LoansDisburse);

        // A second, independent session for the same staff member.
        $otherToken = $staff->createToken('other-device')->plainTextToken;

        $firstToken = $this->signInWithTwoFactor($staff->fresh(), self::PASSWORD, self::SECRET);

        $this->tokenRequest($firstToken, 'POST', '/api/v1/admin/auth/reauthenticate', [
            'password' => self::PASSWORD,
            'code' => $this->currentOtp(),
        ])->assertOk();

        $loan = Loan::factory()->pendingDisbursement()->create();
        $bankAccount = BankAccount::factory()->approved()->create([
            'account_purpose' => BankAccountPurpose::LoanDisbursement,
        ]);

        // The other session's token was never reauthenticated.
        $response = $this->tokenRequest($otherToken, 'POST', "/api/v1/admin/loans/{$loan->id}/disburse", [
            'bank_account_id' => $bankAccount->id,
        ]);

        $response->assertStatus(428);
    }

    #[Test]
    public function an_operation_not_named_in_the_reauthentication_list_is_unaffected(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        [$staff, $token] = $this->realSignedInStaff(Permission::LoansView, Permission::LoansApprove);

        $maker = Staff::factory()->create();
        $loan = Loan::factory()->create(['created_by' => $maker->id]);

        // loan.approve is not in reauthentication_required_operations, so no
        // 428 should ever be raised here.
        $response = $this->tokenRequest($token, 'POST', "/api/v1/admin/loans/{$loan->id}/approve");

        $response->assertOk();
    }

    /**
     * A staff member holding a privileged permission (which mandates 2FA
     * regardless of role — see Staff::requiresTwoFactor()), signed in for
     * real, with a genuine bearer token.
     */
    private function realSignedInStaff(Permission ...$permissions): array
    {
        $this->seedRolesAndPermissions();

        $staff = Staff::factory()->create(['password' => self::PASSWORD]);
        $staff->givePermissionTo(array_map(static fn (Permission $p): string => $p->value, $permissions));

        $staff->forceFill([
            'two_factor_secret' => self::SECRET,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $staff = $staff->fresh();
        $token = $this->signInWithTwoFactor($staff, self::PASSWORD, self::SECRET);

        return [$staff, $token];
    }

    private function currentOtp(): string
    {
        return app(Google2FA::class)->getCurrentOtp(self::SECRET);
    }
}
