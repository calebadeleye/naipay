<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Controllers;

use App\Domains\Branches\Models\Branch;
use App\Domains\Identity\Models\Staff;
use App\Domains\Staff\Http\Requests\AssignRolesRequest;
use App\Domains\Staff\Http\Requests\ChangeStaffStatusRequest;
use App\Domains\Staff\Http\Requests\SetApprovalLimitRequest;
use App\Domains\Staff\Http\Requests\StoreStaffRequest;
use App\Domains\Staff\Http\Requests\TransferStaffRequest;
use App\Domains\Staff\Http\Requests\UpdateStaffRequest;
use App\Domains\Staff\Http\Resources\StaffAdminResource;
use App\Domains\Staff\Services\StaffManagementService;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff administration.
 *
 * Roles, approval limits, transfers and status changes each get their own
 * endpoint rather than being folded into a general update: they carry
 * different permissions, most require a recorded reason, and separating them
 * keeps a routine profile edit from silently carrying a privilege change.
 */
final class StaffController
{
    public function __construct(
        private readonly StaffManagementService $staffManagement,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['staff_number', 'first_name', 'last_name', 'email', 'username'],
            filters: [
                'status' => FilterType::In,
                'branch_id' => FilterType::Exact,
                'department' => FilterType::Exact,
                'access_scope' => FilterType::Exact,
                'approval_limit' => FilterType::AmountRange,
                'last_login_at' => FilterType::DateRange,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['staff_number', 'first_name', 'last_name', 'email', 'status', 'last_login_at', 'created_at'],
            defaultSort: ['last_name', 'first_name'],
        );

        $query = Staff::query()->with('branch');

        // A branch-scoped administrator sees their own branch's staff only.
        if (! $actor->accessScope()->isGlobal()) {
            $query->where(function ($inner) use ($actor): void {
                $inner->where('branch_id', $actor->branch_id);

                // Without this, a branch-scoped administrator whose own record
                // has no branch would be unable to see themselves.
                $inner->orWhere('id', $actor->getKey());
            });
        }

        $staff = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $staff->through(fn (Staff $member) => new StaffAdminResource($member)),
            message: 'Staff retrieved.',
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $result = $this->staffManagement->create($request->validated(), $actor);

        return ApiResponse::created(
            [
                'staff' => new StaffAdminResource($result['staff']->load('branch')),
                // Shown once. The account is flagged `must_change_password`, so
                // this value stops working the moment it is first used.
                'temporary_password' => $result['temporary_password'],
            ],
            "Staff member {$result['staff']->staff_number} created. Give them the temporary password; they must change it at first sign-in.",
        );
    }

    public function show(Staff $staff): JsonResponse
    {
        return ApiResponse::success(
            new StaffAdminResource($staff->load('branch')),
            'Staff member retrieved.',
        );
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->staffManagement->update($staff, $request->validated(), $actor);

        return ApiResponse::success(
            new StaffAdminResource($updated->load('branch')),
            'Staff member updated successfully.',
        );
    }

    public function transfer(TransferStaffRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $branch = Branch::findOrFail($request->integer('branch_id'));

        $updated = $this->staffManagement->transfer(
            $staff,
            $branch,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new StaffAdminResource($updated->load('branch')),
            "{$updated->fullName()} transferred to {$branch->name}.",
        );
    }

    public function suspend(ChangeStaffStatusRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->staffManagement->suspend(
            $staff,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new StaffAdminResource($updated),
            "{$updated->fullName()} has been suspended and signed out of all sessions.",
        );
    }

    public function reinstate(ChangeStaffStatusRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->staffManagement->reinstate(
            $staff,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new StaffAdminResource($updated),
            "{$updated->fullName()} has been reinstated.",
        );
    }

    public function disable(ChangeStaffStatusRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->staffManagement->disable(
            $staff,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new StaffAdminResource($updated),
            "{$updated->fullName()} has been disabled.",
        );
    }

    public function assignRoles(AssignRolesRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        /** @var array<int, string> $roles */
        $roles = $request->input('roles', []);

        $updated = $this->staffManagement->assignRoles($staff, $roles, $actor);

        return ApiResponse::success(
            new StaffAdminResource($updated),
            "Roles updated for {$updated->fullName()}. Their active sessions have been signed out.",
        );
    }

    public function setApprovalLimit(SetApprovalLimitRequest $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $raw = $request->input('approval_limit');

        $updated = $this->staffManagement->setApprovalLimit(
            $staff,
            $raw === null ? null : Money::fromDecimal((string) $raw),
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new StaffAdminResource($updated),
            $raw === null
                ? "Approval authority removed from {$updated->fullName()}."
                : "Approval limit for {$updated->fullName()} set to {$updated->approval_limit?->format()}.",
        );
    }

    public function resetPassword(Request $request, Staff $staff): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $temporaryPassword = $this->staffManagement->resetPassword($staff, $actor);

        return ApiResponse::success(
            ['temporary_password' => $temporaryPassword],
            "A temporary password has been issued for {$staff->fullName()}. They must change it at next sign-in.",
        );
    }
}
