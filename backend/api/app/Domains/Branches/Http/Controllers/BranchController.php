<?php

declare(strict_types=1);

namespace App\Domains\Branches\Http\Controllers;

use App\Domains\Branches\Enums\BranchStatus;
use App\Domains\Branches\Http\Requests\ChangeBranchStatusRequest;
use App\Domains\Branches\Http\Requests\StoreBranchRequest;
use App\Domains\Branches\Http\Requests\UpdateBranchRequest;
use App\Domains\Branches\Http\Resources\BranchResource;
use App\Domains\Branches\Models\Branch;
use App\Domains\Branches\Services\BranchService;
use App\Domains\Identity\Models\Staff;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BranchController
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['branch_code', 'name', 'city', 'state'],
            filters: [
                'status' => FilterType::In,
                'state' => FilterType::Exact,
                'city' => FilterType::Partial,
                'manager_id' => FilterType::Exact,
                'opened_at' => FilterType::DateRange,
            ],
            sortable: ['branch_code', 'name', 'city', 'state', 'status', 'opened_at', 'created_at'],
            defaultSort: ['name'],
        );

        $query = Branch::query()
            ->with('manager')
            ->withCount('staff');

        $branches = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $branches->through(fn (Branch $branch) => new BranchResource($branch)),
            message: 'Branches retrieved.',
        );
    }

    /**
     * Active branches only, shaped for a searchable dropdown.
     *
     * Separate from `index` so a picker does not carry pagination, counts and
     * manager records it has no use for.
     */
    public function options(): JsonResponse
    {
        $branches = Branch::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'branch_code', 'name'])
            ->map(fn (Branch $branch): array => [
                'value' => $branch->id,
                'label' => $branch->label(),
                'branch_code' => $branch->branch_code,
            ]);

        return ApiResponse::success($branches->all(), 'Branch options retrieved.');
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $branch = $this->branches->create($request->validated(), $actor);

        return ApiResponse::created(
            new BranchResource($branch->load('manager')),
            "Branch {$branch->branch_code} created successfully.",
        );
    }

    public function show(Branch $branch): JsonResponse
    {
        return ApiResponse::success(
            new BranchResource($branch->load('manager')->loadCount('staff')),
            'Branch retrieved.',
        );
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->branches->update($branch, $request->validated(), $actor);

        return ApiResponse::success(
            new BranchResource($updated->load('manager')),
            'Branch updated successfully.',
        );
    }

    /**
     * Suspends, closes or reactivates a branch.
     *
     * There is no delete endpoint: a branch is referenced by every loan and
     * repayment booked against it.
     */
    public function changeStatus(ChangeBranchStatusRequest $request, Branch $branch): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $status = BranchStatus::from($request->string('status')->toString());

        $updated = $this->branches->changeStatus(
            $branch,
            $status,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new BranchResource($updated),
            "Branch {$updated->branch_code} is now {$status->label()}.",
        );
    }
}
