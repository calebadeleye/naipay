<?php

declare(strict_types=1);

namespace App\Domains\Merchants\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Merchants\Http\Requests\MerchantActionRequest;
use App\Domains\Merchants\Http\Requests\StoreMerchantRequest;
use App\Domains\Merchants\Http\Requests\UpdateMerchantRequest;
use App\Domains\Merchants\Http\Resources\MerchantResource;
use App\Domains\Merchants\Models\Merchant;
use App\Domains\Merchants\Services\MerchantOnboardingService;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MerchantController
{
    public function __construct(
        private readonly MerchantOnboardingService $onboarding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            // Identity numbers are deliberately absent: a searchable BVN would
            // let anyone with merchants.view confirm whether a given number is
            // registered, which is the permission's whole point to prevent.
            searchable: ['merchant_number', 'account.account_number', 'first_name', 'last_name', 'phone', 'email'],
            filters: [
                'onboarding_status' => FilterType::In,
                'merchant_status' => FilterType::In,
                'kyc_status' => FilterType::In,
                'risk_rating' => FilterType::In,
                'branch_id' => FilterType::Exact,
                'assigned_officer_id' => FilterType::Exact,
                'state' => FilterType::Exact,
                'created_at' => FilterType::DateRange,
                'approved_at' => FilterType::DateRange,
            ],
            sortable: ['merchant_number', 'first_name', 'last_name', 'onboarding_status', 'created_at', 'approved_at'],
            defaultSort: ['-created_at'],
        );

        $query = Merchant::query()
            ->visibleTo($actor)
            ->with(['branch', 'assignedOfficer', 'account'])
            ->withCount('businesses');

        $merchants = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            // Not detailed: a list never carries unmasked identity numbers,
            // whatever the caller holds.
            $merchants->through(fn (Merchant $merchant) => new MerchantResource($merchant)),
            message: 'Merchants retrieved.',
        );
    }

    public function store(StoreMerchantRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $merchant = $this->onboarding->create($request->validated(), $actor);

        return ApiResponse::created(
            new MerchantResource($merchant->load(['branch', 'assignedOfficer', 'account']), detailed: true),
            "Merchant {$merchant->merchant_number} created as a draft.",
        );
    }

    public function show(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        return ApiResponse::success(
            new MerchantResource(
                $merchant->load(['branch', 'assignedOfficer', 'account', 'businesses.category', 'businesses.subcategory', 'loans']),
                detailed: true,
            ),
            'Merchant retrieved.',
        );
    }

    public function update(UpdateMerchantRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->update($merchant, $request->validated(), $actor);

        return ApiResponse::success(
            new MerchantResource($updated->load(['branch', 'assignedOfficer', 'account']), detailed: true),
            'Merchant updated.',
        );
    }

    public function submit(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->submit($merchant, $actor);

        return ApiResponse::success(
            new MerchantResource($updated, detailed: true),
            "Merchant {$updated->merchant_number} submitted for verification.",
        );
    }

    public function verify(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->markVerified($merchant, $actor);

        return ApiResponse::success(
            new MerchantResource($updated, detailed: true),
            'KYC verified. The merchant is now awaiting approval.',
        );
    }

    /**
     * Final approval, subject to maker-checker: the officer who created the
     * merchant cannot be the one who approves them.
     */
    public function approve(Request $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->approve($merchant, $actor);

        return ApiResponse::success(
            new MerchantResource($updated, detailed: true),
            "Merchant {$updated->merchant_number} approved.",
        );
    }

    public function reject(MerchantActionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->reject($merchant, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(new MerchantResource($updated, detailed: true), 'Merchant rejected.');
    }

    public function suspend(MerchantActionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->suspend($merchant, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(new MerchantResource($updated, detailed: true), 'Merchant suspended.');
    }

    public function reinstate(MerchantActionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->reinstate($merchant, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(new MerchantResource($updated, detailed: true), 'Merchant reinstated.');
    }

    public function returnToDraft(MerchantActionRequest $request, Merchant $merchant): JsonResponse
    {
        $this->authoriseBranchAccess($request, $merchant);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->onboarding->returnToDraft($merchant, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(
            new MerchantResource($updated, detailed: true),
            'Merchant returned to draft for correction.',
        );
    }

    /**
     * Route model binding resolves a merchant by id regardless of branch, so
     * the scope has to be re-applied on every single-record action. A list
     * endpoint filtering correctly means nothing if the detail endpoint will
     * hand over any record by id.
     */
    private function authoriseBranchAccess(Request $request, Merchant $merchant): void
    {
        /** @var Staff $actor */
        $actor = $request->user();

        abort_unless(
            $actor->canAccessBranch($merchant->branch_id),
            404,
            'The requested merchant was not found.',
        );
    }
}
