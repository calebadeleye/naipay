<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\LoanApplications\Http\Requests\ApproveLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Requests\AssessLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Requests\LoanApplicationActionRequest;
use App\Domains\LoanApplications\Http\Requests\RecommendLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Requests\StoreLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Requests\UpdateLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Resources\LoanApplicationResource;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Services\LoanApplicationService;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanApplicationController
{
    private const RELATIONS = ['merchant', 'business', 'loanProduct', 'branch', 'createdBy', 'assessedBy', 'recommendedBy', 'guarantors'];

    public function __construct(
        private readonly LoanApplicationService $applications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['application_number'],
            filters: [
                'status' => FilterType::In,
                'merchant_id' => FilterType::Exact,
                'business_id' => FilterType::Exact,
                'loan_product_id' => FilterType::Exact,
                'branch_id' => FilterType::Exact,
                'created_at' => FilterType::DateRange,
                'submitted_at' => FilterType::DateRange,
            ],
            sortable: ['application_number', 'status', 'requested_amount', 'created_at', 'submitted_at'],
            defaultSort: ['-created_at'],
        );

        $query = LoanApplication::query()
            ->visibleTo($actor)
            ->with(['merchant', 'business', 'loanProduct']);

        $applications = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $applications->through(fn (LoanApplication $application) => new LoanApplicationResource($application)),
            message: 'Loan applications retrieved.',
        );
    }

    public function store(StoreLoanApplicationRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $application = $this->applications->create($request->validated(), $actor);

        return ApiResponse::created(
            new LoanApplicationResource($application->load(self::RELATIONS)),
            "Loan application {$application->application_number} created as a draft.",
        );
    }

    public function show(Request $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        return ApiResponse::success(
            new LoanApplicationResource($application->load(self::RELATIONS)),
            'Loan application retrieved.',
        );
    }

    public function update(UpdateLoanApplicationRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->update($application, $request->validated(), $actor);

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            'Loan application updated.',
        );
    }

    public function submit(Request $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->submit($application, $actor);

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            "Loan application {$updated->application_number} submitted for assessment.",
        );
    }

    public function assess(AssessLoanApplicationRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->assess($application, $request->string('notes')->toString(), $actor);

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            'Assessment recorded.',
        );
    }

    public function recommend(RecommendLoanApplicationRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->recommend($application, $request->string('notes')->toString(), $actor);

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            'Recommendation recorded.',
        );
    }

    /**
     * Final approval, subject to maker-checker: the officer who created the
     * application cannot be the one who approves it.
     */
    public function approve(ApproveLoanApplicationRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validated();

        $updated = $this->applications->approve(
            $application,
            $actor,
            isset($validated['approved_amount']) ? Money::fromDecimal($validated['approved_amount']) : null,
            isset($validated['approved_tenor']) ? (int) $validated['approved_tenor'] : null,
        );

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            "Loan application {$updated->application_number} approved.",
        );
    }

    public function reject(LoanApplicationActionRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->reject($application, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(new LoanApplicationResource($updated->load(self::RELATIONS)), 'Loan application rejected.');
    }

    public function returnToDraft(LoanApplicationActionRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->returnToDraft($application, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(
            new LoanApplicationResource($updated->load(self::RELATIONS)),
            'Loan application returned to draft for correction.',
        );
    }

    public function withdraw(LoanApplicationActionRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseBranchAccess($request, $application);

        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->applications->withdraw($application, $request->string('reason')->toString(), $actor);

        return ApiResponse::success(new LoanApplicationResource($updated->load(self::RELATIONS)), 'Loan application withdrawn.');
    }

    /**
     * Route model binding resolves an application by id regardless of branch,
     * so the scope has to be re-applied on every single-record action.
     */
    private function authoriseBranchAccess(Request $request, LoanApplication $application): void
    {
        /** @var Staff $actor */
        $actor = $request->user();

        abort_unless(
            $actor->canAccessBranch($application->branch_id),
            404,
            'The requested loan application was not found.',
        );
    }
}
