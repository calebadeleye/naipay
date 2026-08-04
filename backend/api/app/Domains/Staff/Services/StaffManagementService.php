<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\StaffStatus;
use App\Domains\Identity\Models\Staff;
use App\Domains\Identity\Services\AuthenticationService;
use App\Domains\Identity\Services\PasswordService;
use App\Support\Exceptions\DomainException;
use App\Support\Money\Money;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff lifecycle: creation, transfer, suspension, role assignment and
 * approval limits.
 *
 * Several of these operations are the ones an attacker with a foothold would
 * reach for — granting themselves a role, raising their own approval limit — so
 * self-service is refused explicitly rather than left to the permission system.
 */
final class StaffManagementService
{
    private const MODULE = 'staff';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
        private readonly PasswordService $passwords,
        private readonly AuthenticationService $authentication,
    ) {}

    /**
     * Creates a staff account with a generated temporary password.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{staff: Staff, temporary_password: string}
     */
    public function create(array $attributes, Staff $actor): array
    {
        // Generated rather than chosen by the creating administrator: a
        // password someone else picked is a password someone else knows, and
        // `must_change_password` guarantees it stops working at first use.
        $temporaryPassword = Str::random(16).'aA1!';

        $staff = DB::transaction(function () use ($attributes, $actor, $temporaryPassword): Staff {
            $this->assertBranchAccepts($attributes['branch_id'] ?? null);

            // Roles are a relationship, not a column. Mass assignment stays
            // guarded, so they are lifted out and applied through the audited
            // role-assignment path below.
            $roles = $attributes['roles'] ?? null;
            unset($attributes['roles']);

            $staff = new Staff($attributes);
            $staff->staff_number = $this->references->next('staff');
            $staff->created_by = $actor->getKey();
            $staff->status = StaffStatus::PendingActivation;

            $staff->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'password_changed_at' => now(),
            ])->save();

            if (is_array($roles) && $roles !== []) {
                $this->assignRoles($staff, $roles, $actor, withinTransaction: true);
            }

            $this->audit->recordCreation('staff.created', self::MODULE, $staff, $actor);

            return $staff;
        });

        return [
            'staff' => $staff->fresh(),
            'temporary_password' => $temporaryPassword,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Staff $staff, array $attributes, Staff $actor): Staff
    {
        return DB::transaction(function () use ($staff, $attributes, $actor): Staff {
            $before = $staff->getAttributes();

            if (array_key_exists('branch_id', $attributes)) {
                $this->assertBranchAccepts($attributes['branch_id']);
            }

            $staff->fill($attributes);
            $staff->save();

            $this->audit->recordChange('staff.updated', self::MODULE, $staff, $before, actor: $actor);

            return $staff->fresh();
        });
    }

    /**
     * Moves a staff member to another branch.
     *
     * Recorded as its own action rather than a generic update: a transfer
     * changes what someone can see, and an auditor asks about it specifically.
     */
    public function transfer(Staff $staff, Branch $branch, string $reason, Staff $actor): Staff
    {
        if ($staff->branch_id === $branch->getKey()) {
            throw new DomainException('This staff member is already assigned to that branch.');
        }

        $this->assertBranchAccepts($branch->getKey());

        return DB::transaction(function () use ($staff, $branch, $reason, $actor): Staff {
            $before = $staff->getAttributes();

            $staff->branch_id = $branch->getKey();
            $staff->save();

            $this->audit->recordChange(
                'staff.transferred',
                self::MODULE,
                $staff,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $staff->fresh();
        });
    }

    /**
     * Suspends an account and cuts every session immediately.
     */
    public function suspend(Staff $staff, string $reason, Staff $actor): Staff
    {
        $this->assertNotSelf($staff, $actor, 'suspend your own account');

        if ($staff->status === StaffStatus::Suspended) {
            throw new DomainException('This staff member is already suspended.');
        }

        return DB::transaction(function () use ($staff, $reason, $actor): Staff {
            $before = $staff->getAttributes();

            $staff->forceFill([
                'status' => StaffStatus::Suspended,
                'suspension_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
            ])->save();

            // Status alone would only stop the next sign-in; existing bearer
            // tokens would keep working until they expired.
            $this->authentication->signOutAllSessions($staff);

            $this->audit->recordChange(
                'staff.suspended',
                self::MODULE,
                $staff,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $staff->fresh();
        });
    }

    public function reinstate(Staff $staff, string $reason, Staff $actor): Staff
    {
        if ($staff->status !== StaffStatus::Suspended) {
            throw new DomainException('Only a suspended staff member can be reinstated.');
        }

        return DB::transaction(function () use ($staff, $reason, $actor): Staff {
            $before = $staff->getAttributes();

            $staff->forceFill([
                'status' => StaffStatus::Active,
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by' => null,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->audit->recordChange(
                'staff.reinstated',
                self::MODULE,
                $staff,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $staff->fresh();
        });
    }

    /**
     * Permanently revokes access. The record itself is retained.
     */
    public function disable(Staff $staff, string $reason, Staff $actor): Staff
    {
        $this->assertNotSelf($staff, $actor, 'disable your own account');
        $this->assertNotLastSuperAdministrator($staff);

        return DB::transaction(function () use ($staff, $reason, $actor): Staff {
            $before = $staff->getAttributes();

            $staff->forceFill([
                'status' => StaffStatus::Disabled,
                'suspension_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by' => $actor->getKey(),
            ])->save();

            $this->authentication->signOutAllSessions($staff);

            $this->audit->recordChange(
                'staff.disabled',
                self::MODULE,
                $staff,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $staff->fresh();
        });
    }

    /**
     * Replaces a staff member's roles.
     *
     * @param  array<int, string>  $roles
     */
    public function assignRoles(
        Staff $staff,
        array $roles,
        Staff $actor,
        bool $withinTransaction = false,
    ): Staff {
        // Escalating your own privileges is the single most valuable move
        // available to a compromised account, so it is refused outright rather
        // than merely permission-gated.
        $this->assertNotSelf($staff, $actor, 'change your own roles');

        $apply = function () use ($staff, $roles, $actor): Staff {
            $previous = $staff->roleNames()->all();

            $staff->syncRoles($roles);

            $this->audit->record(
                action: 'staff.roles_changed',
                module: self::MODULE,
                subject: $staff,
                oldValues: ['roles' => $previous],
                newValues: ['roles' => array_values($roles)],
                eventType: 'update',
                actor: $actor,
            );

            // A role change can newly mandate two-factor. Existing tokens carry
            // the old permission set as abilities, so they must not survive.
            $this->authentication->signOutAllSessions($staff);

            return $staff->fresh();
        };

        return $withinTransaction ? $apply() : DB::transaction($apply);
    }

    /**
     * Sets how much a staff member may approve.
     *
     * Null removes approval authority entirely, which is distinct from a limit
     * of zero — the latter says they hold the role but currently approve
     * nothing.
     */
    public function setApprovalLimit(
        Staff $staff,
        ?Money $limit,
        string $reason,
        Staff $actor,
    ): Staff {
        $this->assertNotSelf($staff, $actor, 'change your own approval limit');

        if ($limit !== null && $limit->isNegative()) {
            throw new DomainException(
                'An approval limit cannot be negative.',
                ['approval_limit' => ['The approval limit must be zero or greater.']],
            );
        }

        return DB::transaction(function () use ($staff, $limit, $reason, $actor): Staff {
            $previous = $staff->approval_limit;

            $staff->forceFill(['approval_limit' => $limit?->toDecimalString()])->save();

            $this->audit->record(
                action: 'staff.approval_limit_changed',
                module: self::MODULE,
                subject: $staff,
                oldValues: ['approval_limit' => $previous?->toDecimalString()],
                newValues: ['approval_limit' => $limit?->toDecimalString()],
                reason: $reason,
                eventType: 'update',
                actor: $actor,
            );

            return $staff->fresh();
        });
    }

    /**
     * Issues a new temporary password on an administrator's instruction.
     */
    public function resetPassword(Staff $staff, Staff $actor): string
    {
        $temporaryPassword = Str::random(16).'aA1!';

        DB::transaction(function () use ($staff, $temporaryPassword, $actor): void {
            $this->passwords->setTemporaryPassword($staff, $temporaryPassword);

            // The password itself is never written to the trail — only that a
            // reset happened, and who ordered it.
            $this->audit->record(
                action: 'staff.password_reset_by_administrator',
                module: self::MODULE,
                subject: $staff,
                eventType: 'update',
                actor: $actor,
            );
        });

        return $temporaryPassword;
    }

    private function assertNotSelf(Staff $subject, Staff $actor, string $operation): void
    {
        if ($subject->getKey() === $actor->getKey()) {
            throw new DomainException(
                "You cannot {$operation}. Another authorised administrator must do it.",
            );
        }
    }

    /**
     * Prevents the organisation locking itself out of its own access control.
     */
    private function assertNotLastSuperAdministrator(Staff $staff): void
    {
        if (! $staff->isSuperAdministrator()) {
            return;
        }

        $remaining = Staff::query()
            ->whereKeyNot($staff->getKey())
            ->where('status', StaffStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->where('name', 'super-administrator'))
            ->count();

        if ($remaining === 0) {
            throw new DomainException(
                'This is the only active Super Administrator. Appoint another before disabling this account.',
            );
        }
    }

    private function assertBranchAccepts(mixed $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $branch = Branch::find($branchId);

        if ($branch === null) {
            throw new DomainException(
                'The selected branch was not found.',
                ['branch_id' => ['The selected branch does not exist.']],
            );
        }

        if (! $branch->status->acceptsNewBusiness()) {
            throw new DomainException(
                "{$branch->name} is {$branch->status->label()} and cannot take new staff.",
                ['branch_id' => ['This branch is not active.']],
            );
        }
    }
}
