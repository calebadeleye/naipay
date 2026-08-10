<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Domains\Approvals\Contracts\RequiresMakerChecker;
use App\Domains\Approvals\Services\MakerCheckerGuard;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\MakerCheckerViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stand-in for any record subject to segregation of duties — a repayment, a
 * loan approval, a reversal. The guard only needs the maker and the operation.
 */
final class PendingOperation implements RequiresMakerChecker
{
    public function __construct(
        private readonly ?int $makerId,
        private readonly string $operation,
    ) {}

    public function makerId(): ?int
    {
        return $this->makerId;
    }

    public function makerCheckerOperation(): string
    {
        return $this->operation;
    }
}

final class MakerCheckerGuardTest extends TestCase
{
    use RefreshDatabase;

    private MakerCheckerGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new MakerCheckerGuard;
    }

    #[Test]
    public function a_staff_member_can_now_approve_an_operation_they_created(): void
    {
        // The business decided self-approval should no longer be blocked —
        // naipay.maker_checker.enforced_operations is empty by default. See
        // config/naipay.php and docs/roles-and-permissions.md.
        $staff = Staff::factory()->create();

        $operation = new PendingOperation((int) $staff->getKey(), 'repayment.approve');

        $this->assertTrue($this->guard->canApprove($staff, $operation));

        // Does not throw.
        $this->guard->assertCanApprove($staff, $operation);
    }

    #[Test]
    public function a_different_staff_member_may_also_approve(): void
    {
        $maker = Staff::factory()->create();
        $checker = Staff::factory()->create();

        $operation = new PendingOperation((int) $maker->getKey(), 'repayment.approve');

        $this->assertTrue($this->guard->canApprove($checker, $operation));

        // Does not throw.
        $this->guard->assertCanApprove($checker, $operation);
    }

    #[Test]
    public function a_system_generated_record_may_be_approved_by_anyone_authorised(): void
    {
        // Explicitly enforce this operation for the test, so the assertion
        // exercises the null-maker branch rather than the (now default)
        // nothing-is-enforced branch.
        config(['naipay.maker_checker.enforced_operations' => ['repayment.approve']]);

        $staff = Staff::factory()->create();

        // No maker to conflict with — e.g. an operation raised by the
        // scheduler rather than a person.
        $operation = new PendingOperation(null, 'repayment.approve');

        $this->assertTrue($this->guard->canApprove($staff, $operation));
    }

    #[Test]
    public function an_operation_outside_the_enforced_list_is_not_constrained(): void
    {
        $staff = Staff::factory()->create();

        // Editing a merchant's phone number is not a maker-checked operation.
        $operation = new PendingOperation((int) $staff->getKey(), 'merchant.update_contact');

        $this->assertTrue($this->guard->canApprove($staff, $operation));
    }

    #[Test]
    #[DataProvider('previouslyEnforcedOperations')]
    public function operations_the_brief_once_named_now_allow_self_approval_by_default(string $operation): void
    {
        $this->assertFalse(
            $this->guard->isEnforcedFor($operation),
            "[{$operation}] should not be enforced by default."
        );

        $staff = Staff::factory()->create();

        $this->assertTrue(
            $this->guard->canApprove($staff, new PendingOperation((int) $staff->getKey(), $operation)),
            "[{$operation}] should now allow self-approval."
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function previouslyEnforcedOperations(): array
    {
        return [
            'loan approval' => ['loan.approve'],
            'loan disbursement' => ['loan.disburse'],
            'repayment approval' => ['repayment.approve'],
            'payment reversal' => ['repayment.reverse'],
            'manual credit' => ['ledger.manual_credit'],
            'manual debit' => ['ledger.manual_debit'],
            'interest rate change' => ['loan.interest_rate_change'],
            'loan restructuring' => ['loan.restructure'],
            'write-off' => ['loan.write_off'],
            'account closure' => ['account.close'],
            'sensitive merchant change' => ['merchant.sensitive_update'],
            'bank account change' => ['bank_account.change'],
        ];
    }

    #[Test]
    public function the_enforced_list_is_configuration_rather_than_code(): void
    {
        // Empty by default (the business's current policy)...
        $this->assertFalse($this->guard->isEnforcedFor('some.new.operation'));

        $staff = Staff::factory()->create();
        $operation = new PendingOperation((int) $staff->getKey(), 'some.new.operation');
        $this->assertTrue($this->guard->canApprove($staff, $operation));

        // ...but the mechanism itself still works if re-enabled — this
        // decision is a config change, not a code change.
        config(['naipay.maker_checker.enforced_operations' => ['some.new.operation']]);

        $this->assertTrue($this->guard->isEnforcedFor('some.new.operation'));
        $this->assertFalse($this->guard->canApprove($staff, $operation));
        $this->expectException(MakerCheckerViolationException::class);
        $this->guard->assertCanApprove($staff, $operation);
    }

    #[Test]
    public function the_violation_is_reported_as_forbidden_with_an_actionable_message(): void
    {
        // The exception path itself still needs to behave correctly whenever
        // an operation is configured as enforced.
        config(['naipay.maker_checker.enforced_operations' => ['loan.disburse']]);

        $staff = Staff::factory()->create();
        $operation = new PendingOperation((int) $staff->getKey(), 'loan.disburse');

        try {
            $this->guard->assertCanApprove($staff, $operation);
            $this->fail('Expected a maker-checker violation.');
        } catch (MakerCheckerViolationException $e) {
            $this->assertSame(403, $e->status());
            $this->assertStringContainsString('different authorised officer', $e->getMessage());
        }
    }

    #[Test]
    public function operations_that_move_money_require_reauthentication(): void
    {
        foreach ([
            'loan.disburse',
            'repayment.reverse',
            'ledger.manual_credit',
            'ledger.manual_debit',
            'loan.write_off',
            'bank_account.change',
        ] as $operation) {
            $this->assertTrue(
                $this->guard->requiresReauthentication($operation),
                "[{$operation}] should require re-authentication at the point of action."
            );
        }

        $this->assertFalse($this->guard->requiresReauthentication('merchants.view'));
    }
}
