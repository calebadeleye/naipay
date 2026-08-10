<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Investors\Http\Requests\ChangeInvestorStatusRequest;
use App\Domains\Investors\Http\Requests\StoreInvestorRequest;
use App\Domains\Investors\Http\Requests\UpdateInvestorRequest;
use App\Domains\Investors\Http\Resources\InvestorAdminResource;
use App\Domains\Investors\Models\Investor;
use App\Domains\Investors\Services\InvestorManagementService;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Investor account administration — creating and managing the accounts that
 * sign in to the read-only investor portal. Not to be confused with anything
 * under the `investor` guard: this controller sits behind `auth:staff` and is
 * how a member of staff manages those accounts, not how an investor uses one.
 */
final class InvestorManagementController
{
    public function __construct(
        private readonly InvestorManagementService $investorManagement,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['investor_number', 'name', 'email'],
            filters: [
                'status' => FilterType::In,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['investor_number', 'name', 'email', 'status', 'created_at'],
            defaultSort: ['name'],
        );

        $query = Investor::query()->with('createdBy');

        $investors = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $investors->through(fn (Investor $investor) => new InvestorAdminResource($investor)),
            message: 'Investors retrieved.',
        );
    }

    public function store(StoreInvestorRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $result = $this->investorManagement->create($request->validated(), $actor);

        return ApiResponse::created(
            [
                'investor' => new InvestorAdminResource($result['investor']->load('createdBy')),
                // Shown once. Relay it to the investor through a secure channel.
                'temporary_password' => $result['temporary_password'],
            ],
            "Investor {$result['investor']->investor_number} created.",
        );
    }

    public function show(Investor $investor): JsonResponse
    {
        return ApiResponse::success(
            new InvestorAdminResource($investor->load('createdBy')),
            'Investor retrieved.',
        );
    }

    public function update(UpdateInvestorRequest $request, Investor $investor): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->investorManagement->update($investor, $request->validated(), $actor);

        return ApiResponse::success(
            new InvestorAdminResource($updated->load('createdBy')),
            'Investor updated successfully.',
        );
    }

    public function suspend(ChangeInvestorStatusRequest $request, Investor $investor): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->investorManagement->suspend(
            $investor,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new InvestorAdminResource($updated->load('createdBy')),
            "{$updated->fullName()} has been suspended and signed out.",
        );
    }

    public function reinstate(ChangeInvestorStatusRequest $request, Investor $investor): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->investorManagement->reinstate(
            $investor,
            $request->string('reason')->toString(),
            $actor,
        );

        return ApiResponse::success(
            new InvestorAdminResource($updated->load('createdBy')),
            "{$updated->fullName()} has been reinstated.",
        );
    }
}
