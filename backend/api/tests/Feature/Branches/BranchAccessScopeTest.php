<?php

declare(strict_types=1);

namespace Tests\Feature\Branches;

use App\Domains\Branches\Concerns\BelongsToBranch;
use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Enums\AccessScope;
use App\Domains\Identity\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stands in for any branch-owned record — a merchant, a loan, a repayment.
 *
 * Backed by the staff table because it already has a branch_id, so the scope is
 * exercised against real SQL rather than a fixture. What is under test is the
 * trait, not this model.
 */
final class BranchOwnedRecord extends Model
{
    use BelongsToBranch;

    public $timestamps = false;

    protected $table = 'staff';
}

/**
 * Branch access scoping.
 *
 * This is the boundary that stops one branch reading another's portfolio.
 * Every later domain inherits it, so its failure modes matter more than most:
 * a scope that silently returns everything is indistinguishable from a working
 * one until someone notices a branch manager reading the whole loan book.
 */
final class BranchAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_branch_scoped_staff_member_sees_only_their_own_branch(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $staff = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Branch,
        ]);

        Staff::factory()->count(3)->create(['branch_id' => $lagos->id]);
        Staff::factory()->count(4)->create(['branch_id' => $kano->id]);

        $visible = BranchOwnedRecord::query()->visibleTo($staff->fresh())->get();

        // Their own record plus the three colleagues, and nothing from Kano.
        $this->assertCount(4, $visible);
        $this->assertTrue($visible->every(fn (BranchOwnedRecord $r): bool => $r->branch_id === $lagos->id));
    }

    #[Test]
    public function a_globally_scoped_staff_member_sees_everything(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $staff = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Global,
        ]);

        Staff::factory()->count(3)->create(['branch_id' => $lagos->id]);
        Staff::factory()->count(4)->create(['branch_id' => $kano->id]);

        $this->assertCount(8, BranchOwnedRecord::query()->visibleTo($staff->fresh())->get());
    }

    #[Test]
    public function a_department_scoped_staff_member_crosses_branches(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        // Compliance reviews KYC wherever it was captured; finance reconciles
        // the whole book. Department scope is meant to cross branches.
        $staff = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Department,
            'department' => 'Compliance',
        ]);

        Staff::factory()->create(['branch_id' => $lagos->id]);
        Staff::factory()->create(['branch_id' => $kano->id]);

        $this->assertCount(3, BranchOwnedRecord::query()->visibleTo($staff->fresh())->get());
    }

    #[Test]
    public function a_branch_scoped_staff_member_with_no_branch_sees_nothing(): void
    {
        Branch::factory()->create();

        $staff = Staff::factory()->create([
            'branch_id' => null,
            'access_scope' => AccessScope::Branch,
        ]);

        Staff::factory()->count(5)->create(['branch_id' => Branch::factory()->create()->id]);

        // Failing closed is the only safe direction. An unassigned account must
        // not inherit organisation-wide visibility by omission — that is the
        // failure mode where a misconfigured account quietly sees everything.
        $this->assertCount(0, BranchOwnedRecord::query()->visibleTo($staff->fresh())->get());
    }

    #[Test]
    public function the_scope_composes_with_other_constraints(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $staff = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Branch,
        ]);

        Staff::factory()->create(['branch_id' => $lagos->id, 'department' => 'Credit']);
        Staff::factory()->create(['branch_id' => $lagos->id, 'department' => 'Finance']);
        Staff::factory()->create(['branch_id' => $kano->id, 'department' => 'Credit']);

        $visible = BranchOwnedRecord::query()
            ->visibleTo($staff->fresh())
            ->where('department', 'Credit')
            ->get();

        $this->assertCount(1, $visible);
        $this->assertSame($lagos->id, $visible->first()->branch_id);
    }

    // --- The predicate used for single-record checks -----------------------

    #[Test]
    public function can_access_branch_matches_the_query_scope(): void
    {
        $lagos = Branch::factory()->create();
        $kano = Branch::factory()->create();

        $branchScoped = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Branch,
        ])->fresh();

        $this->assertTrue($branchScoped->canAccessBranch($lagos->id));
        $this->assertFalse($branchScoped->canAccessBranch($kano->id));
        $this->assertFalse($branchScoped->canAccessBranch(null));

        $globalScoped = Staff::factory()->create([
            'branch_id' => $lagos->id,
            'access_scope' => AccessScope::Global,
        ])->fresh();

        $this->assertTrue($globalScoped->canAccessBranch($lagos->id));
        $this->assertTrue($globalScoped->canAccessBranch($kano->id));
        // A global scope covers records not yet assigned to any branch.
        $this->assertTrue($globalScoped->canAccessBranch(null));

        $unassigned = Staff::factory()->create([
            'branch_id' => null,
            'access_scope' => AccessScope::Branch,
        ])->fresh();

        $this->assertFalse($unassigned->canAccessBranch($lagos->id));
    }

    #[Test]
    public function branch_scope_defaults_to_the_narrowest_setting(): void
    {
        $staff = Staff::factory()->create()->fresh();

        // The database default, and the right default: new accounts should not
        // start with organisation-wide reach.
        $this->assertSame(AccessScope::Branch, $staff->accessScope());
    }
}
