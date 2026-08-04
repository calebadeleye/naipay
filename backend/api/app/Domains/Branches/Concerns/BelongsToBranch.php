<?php

declare(strict_types=1);

namespace App\Domains\Branches\Concerns;

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to any record that belongs to a branch — merchants, loans,
 * repayments, applications.
 *
 * Gives every such model the same `visibleTo()` scope, so branch access is
 * enforced identically everywhere instead of each list endpoint inventing its
 * own filter. A missed filter in one report is how one branch ends up seeing
 * another's portfolio.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToBranch
{
    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Restricts a query to the records a staff member is allowed to see.
     *
     * Not a global scope: those are easy to forget to disable for a legitimate
     * organisation-wide report, and easy to bypass by accident. Making it
     * explicit means a query that should be scoped is visibly scoped.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, Staff $staff): void
    {
        $scope = $staff->accessScope();

        // Global reach: no restriction.
        if ($scope === AccessScope::Global) {
            return;
        }

        // Department reach crosses branches by design — compliance reviews KYC
        // wherever it was captured, finance reconciles across the whole book.
        if ($scope === AccessScope::Department) {
            return;
        }

        // Branch reach, with no branch assigned, sees nothing. Failing closed
        // is the only safe direction: an unassigned account must not inherit
        // organisation-wide visibility by omission.
        if ($staff->branch_id === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($query->getModel()->qualifyColumn('branch_id'), $staff->branch_id);
    }
}
