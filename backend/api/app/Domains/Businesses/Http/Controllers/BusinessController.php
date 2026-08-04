<?php

declare(strict_types=1);

namespace App\Domains\Businesses\Http\Controllers;

use App\Domains\Businesses\Enums\BusinessStatus;
use App\Domains\Businesses\Http\Requests\StoreBusinessRequest;
use App\Domains\Businesses\Http\Requests\UpdateBusinessRequest;
use App\Domains\Businesses\Http\Resources\BusinessResource;
use App\Domains\Businesses\Models\Business;
use App\Domains\Businesses\Services\BusinessService;
use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BusinessController
{
    public function __construct(
        private readonly BusinessService $businesses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['business_number', 'business_name', 'registered_business_name', 'cac_registration_number'],
            filters: [
                'status' => FilterType::In,
                'verification_status' => FilterType::In,
                'business_type' => FilterType::In,
                'business_category_id' => FilterType::Exact,
                'business_subcategory_id' => FilterType::Exact,
                'merchant_id' => FilterType::Exact,
                'state' => FilterType::Exact,
                'estimated_monthly_revenue' => FilterType::AmountRange,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['business_number', 'business_name', 'status', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $query = Business::query()
            ->visibleTo($actor)
            ->with(['category', 'subcategory', 'merchant:id,merchant_number,first_name,last_name']);

        $businesses = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $businesses->through(fn (Business $business) => new BusinessResource($business)),
            message: 'Businesses retrieved.',
        );
    }

    /**
     * The legal-structure dropdown, with whether each expects CAC registration.
     */
    public function typeOptions(): JsonResponse
    {
        return ApiResponse::success(
            $this->businesses->businessTypeOptions(),
            'Business type options retrieved.',
        );
    }

    public function store(StoreBusinessRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $business = $this->businesses->create($merchant, $request->validated(), $actor);

        return ApiResponse::created(
            new BusinessResource($business->load(['category', 'subcategory'])),
            "Business {$business->business_number} added to {$merchant->fullName()}.",
        );
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authoriseBranchAccess($request, $business->merchant);

        return ApiResponse::success(
            new BusinessResource($business->load(['category', 'subcategory', 'merchant'])),
            'Business retrieved.',
        );
    }

    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        $this->authoriseBranchAccess($request, $business->merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->businesses->update($business, $request->validated(), $actor);

        return ApiResponse::success(
            new BusinessResource($updated->load(['category', 'subcategory'])),
            'Business updated.',
        );
    }

    /**
     * Records the outcome of a field verification, which is what makes a
     * business operational.
     */
    public function verify(Request $request, Business $business): JsonResponse
    {
        $this->authoriseBranchAccess($request, $business->merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->businesses->verify($business, $actor);

        return ApiResponse::success(
            new BusinessResource($updated),
            "Business {$updated->business_number} verified and activated.",
        );
    }

    public function rejectVerification(Request $request, Business $business): JsonResponse
    {
        $this->authoriseBranchAccess($request, $business->merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $updated = $this->businesses->rejectVerification($business, $validated['reason'], $actor);

        return ApiResponse::success(new BusinessResource($updated), 'Business verification rejected.');
    }

    public function changeStatus(Request $request, Business $business): JsonResponse
    {
        $this->authoriseBranchAccess($request, $business->merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::enum(BusinessStatus::class)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $updated = $this->businesses->changeStatus(
            $business,
            BusinessStatus::from($validated['status']),
            $validated['reason'],
            $actor,
        );

        return ApiResponse::success(
            new BusinessResource($updated),
            "Business {$updated->business_number} is now {$updated->status->label()}.",
        );
    }

    /**
     * Businesses hold no branch of their own — they belong to a merchant, and
     * the merchant belongs to a branch. Route model binding ignores that, so
     * the scope is re-applied here on every single-record action.
     */
    private function authoriseBranchAccess(Request $request, Merchant $merchant): void
    {
        /** @var Staff $actor */
        $actor = $request->user();

        abort_unless(
            $actor->canAccessBranch($merchant->branch_id),
            404,
            'The requested business was not found.',
        );
    }
}
