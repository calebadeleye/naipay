<?php

declare(strict_types=1);

namespace App\Domains\Branches\Services;

use App\Domains\Audit\Services\AuditLogger;
use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Models\Staff;
use App\Support\Exceptions\DomainException;
use App\Support\Sequences\ReferenceGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Branch lifecycle.
 *
 * Every mutation runs inside a transaction and writes an audit entry, so the
 * branch record and its history can never disagree about what happened.
 */
final class BranchService
{
    private const MODULE = 'branches';

    public function __construct(
        private readonly ReferenceGenerator $references,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, Staff $actor): Branch
    {
        return DB::transaction(function () use ($attributes, $actor): Branch {
            $branch = new Branch($attributes);

            // Allocated inside the transaction and late, once the branch is
            // certain to be written.
            $branch->branch_code = $attributes['branch_code'] ?? $this->references->next('branch');
            $branch->created_by = $actor->getKey();
            $branch->status = BranchStatus::from($attributes['status'] ?? BranchStatus::Active->value);

            $this->assertManagerIsAssignable($attributes['manager_id'] ?? null);

            $branch->save();

            $this->audit->recordCreation('branch.created', self::MODULE, $branch, $actor);

            return $branch->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Branch $branch, array $attributes, Staff $actor): Branch
    {
        return DB::transaction(function () use ($branch, $attributes, $actor): Branch {
            // Captured before the fill so the audit entry records the real
            // before-state rather than the already-mutated model.
            $before = $branch->getAttributes();

            if (array_key_exists('manager_id', $attributes)) {
                $this->assertManagerIsAssignable($attributes['manager_id']);
            }

            $branch->fill($attributes);
            $branch->save();

            $this->audit->recordChange('branch.updated', self::MODULE, $branch, $before, actor: $actor);

            return $branch->fresh();
        });
    }

    /**
     * Suspends or closes a branch.
     *
     * Never deletes: a branch is referenced by every loan and repayment ever
     * booked against it, and those must stay attributable.
     */
    public function changeStatus(
        Branch $branch,
        BranchStatus $status,
        string $reason,
        Staff $actor,
    ): Branch {
        if ($branch->status === $status) {
            throw new DomainException("This branch is already {$status->label()}.");
        }

        if ($status === BranchStatus::Closed) {
            $this->assertBranchCanClose($branch);
        }

        return DB::transaction(function () use ($branch, $status, $reason, $actor): Branch {
            $before = $branch->getAttributes();

            $branch->status = $status;
            $branch->closed_at = $status === BranchStatus::Closed ? now() : null;
            $branch->save();

            $this->audit->recordChange(
                "branch.{$status->value}",
                self::MODULE,
                $branch,
                $before,
                reason: $reason,
                actor: $actor,
            );

            return $branch->fresh();
        });
    }

    /**
     * A branch with staff still assigned cannot be closed — they would be left
     * scoped to a branch that no longer accepts business, and quietly lose
     * access to everything.
     */
    private function assertBranchCanClose(Branch $branch): void
    {
        $remainingStaff = Staff::query()->where('branch_id', $branch->getKey())->count();

        if ($remainingStaff > 0) {
            throw new DomainException(
                "This branch still has {$remainingStaff} staff member(s) assigned. Transfer them before closing it.",
            );
        }
    }

    private function assertManagerIsAssignable(mixed $managerId): void
    {
        if ($managerId === null) {
            return;
        }

        $manager = Staff::find($managerId);

        if ($manager === null) {
            throw new DomainException(
                'The selected manager was not found.',
                ['manager_id' => ['The selected manager does not exist.']],
            );
        }

        if (! $manager->status->canAuthenticate()) {
            throw new DomainException(
                'A suspended or disabled staff member cannot manage a branch.',
                ['manager_id' => ['This staff member is not active.']],
            );
        }
    }
}
