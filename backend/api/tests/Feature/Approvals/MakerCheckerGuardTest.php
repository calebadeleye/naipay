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
    public function a_staff_member_cannot_approve_an_operation_they_created(): void
    {
        $staff = Staff::factory()->create();

        $operation = new PendingOperation((int) $staff->getKey(), 'repayment.approve');

        $this->assertFalse($this->guard->canApprove($staff, $operation));

        $this->expectException(MakerCheckerViolationException::class);

        $this->guard->assertCanApprove($staff, $operation);
    }

    #[Test]
    public function a_different_staff_member_may_approve(): void
    {
        $maker = Staff::factory()->create();
        $checker = Staff::factory()->create();

        $operation = new PendingOperation((int) $maker->getKey(), 'repayment.approve');

        $this->assertTrue($this->guard->canApprove($checker, $operation));

        // Does not throw.
        $this->guard->assertCanApprove($checker, $operation);
    }

    #[Test]
    public function the_violation_is_reported_as_forbidden_with_an_actionable_message(): void
    {
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
    public function a_system_generated_record_may_be_approved_by_anyone_authorised(): void
    {
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
    #[DataProvider('enforcedOperations')]
    public function the_control_covers_every_sensitive_operation_the_brief_names(string $operation): void
    {
        $this->assertTrue(
            $this->guard->isEnforcedFor($operation),
            "[{$operation}] must be subject to maker-checker."
        );

        $staff = Staff::factory()->create();

        $this->assertFalse(
            $this->guard->canApprove($staff, new PendingOperation((int) $staff->getKey(), $operation)),
            "[{$operation}] must refuse self-approval."
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function enforcedOperations(): array
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
            'staff role change' => ['staff.role_change'],
            'approval limit change' => ['staff.approval_limit_change'],
            'bank account change' => ['bank_account.change'],
        ];
    }

    #[Test]
    public function the_enforced_list_is_configuration_rather_than_code(): void
    {
        $this->assertFalse($this->guard->isEnforcedFor('some.new.operation'));

        // The business can extend the control without a deployment.
        config([
            'naipay.maker_checker.enforced_operations' => ['some.new.operation'],
        ]);

        $this->assertTrue($this->guard->isEnforcedFor('some.new.operation'));

        $staff = Staff::factory()->create();

        $this->assertFalse(
            $this->guard->canApprove($staff, new PendingOperation((int) $staff->getKey(), 'some.new.operation'))
        );
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
