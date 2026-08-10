<?php

declare(strict_types=1);

namespace App\Domains\LoanApplications\Http\Controllers\Merchant;

use App\Domains\LoanApplications\Http\Requests\StoreMerchantLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Requests\WithdrawMerchantLoanApplicationRequest;
use App\Domains\LoanApplications\Http\Resources\LoanApplicationSelfResource;
use App\Domains\LoanApplications\Models\LoanApplication;
use App\Domains\LoanApplications\Services\LoanApplicationService;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A merchant applying for a loan and tracking their own applications through
 * the self-service portal.
 *
 * A new application always lands in Draft — an officer reviews and submits
 * it, the same way it would for one they originated on the merchant's
 * behalf. This avoids the portal needing to collect guarantors itself for
 * products that require them, which is out of scope for now.
 */
final class LoanApplicationController
{
    private const RELATIONS = ['business', 'loanProduct', 'guarantors'];

    public function __construct(
        private readonly LoanApplicationService $applications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['application_number'],
            filters: [
                'status' => FilterType::In,
                'business_id' => FilterType::Exact,
            ],
            sortable: ['application_number', 'status', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $query = LoanApplication::query()->where('merchant_id', $merchant->id)->with(['business', 'loanProduct']);

        $applications = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $applications->through(fn (LoanApplication $application) => new LoanApplicationSelfResource($application)),
            message: 'Loan applications retrieved.',
        );
    }

    public function show(Request $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseOwnership($request, $application);

        return ApiResponse::success(
            new LoanApplicationSelfResource($application->load(self::RELATIONS)),
            'Loan application retrieved.',
        );
    }

    public function store(StoreMerchantLoanApplicationRequest $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $application = $this->applications->create(
            [...$request->validated(), 'merchant_id' => $merchant->id],
            $merchant,
        );

        return ApiResponse::created(
            new LoanApplicationSelfResource($application->load(self::RELATIONS)),
            "Application {$application->application_number} created. Every Merchant will review it shortly.",
        );
    }

    public function withdraw(WithdrawMerchantLoanApplicationRequest $request, LoanApplication $application): JsonResponse
    {
        $this->authoriseOwnership($request, $application);

        /** @var Merchant $merchant */
        $merchant = $request->user();

        $updated = $this->applications->withdraw($application, $request->string('reason')->toString(), $merchant);

        return ApiResponse::success(
            new LoanApplicationSelfResource($updated->load(self::RELATIONS)),
            "Application {$updated->application_number} has been withdrawn.",
        );
    }

    private function authoriseOwnership(Request $request, LoanApplication $application): void
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        abort_unless(
            $application->merchant_id === $merchant->id,
            404,
            'The requested loan application was not found.',
        );
    }
}
