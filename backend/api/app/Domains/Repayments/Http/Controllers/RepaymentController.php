<?php

declare(strict_types=1);

namespace App\Domains\Repayments\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Repayments\Http\Requests\RejectRepaymentRequest;
use App\Domains\Repayments\Http\Requests\ReverseRepaymentRequest;
use App\Domains\Repayments\Http\Requests\StoreRepaymentRequest;
use App\Domains\Repayments\Http\Requests\VerifyRepaymentRequest;
use App\Domains\Repayments\Http\Resources\RepaymentResource;
use App\Domains\Repayments\Models\Repayment;
use App\Domains\Repayments\Services\RepaymentService;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RepaymentController
{
    private const RELATIONS = ['loan', 'merchant', 'business', 'branch', 'receivingBankAccount', 'recordedBy', 'verifiedBy', 'approvedBy', 'rejectedBy', 'reversedBy'];

    public function __construct(
        private readonly RepaymentService $repayments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['repayment_reference', 'bank_reference'],
            filters: [
                'status' => FilterType::In,
                'loan_id' => FilterType::Exact,
                'merchant_id' => FilterType::Exact,
                'branch_id' => FilterType::Exact,
                'payment_date' => FilterType::DateRange,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['repayment_reference', 'status', 'amount', 'payment_date', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $query = Repayment::query()->visibleTo($actor)->with(['loan', 'merchant']);

        $repayments = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $repayments->through(fn (Repayment $repayment) => new RepaymentResource($repayment)),
            message: 'Repayments retrieved.',
        );
    }

    public function show(Repayment $repayment): JsonResponse
    {
        return ApiResponse::success(
            new RepaymentResource($repayment->load([...self::RELATIONS, 'allocations.scheduleEntry'])),
            'Repayment retrieved.',
        );
    }

    public function store(StoreRepaymentRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $repayment = $this->repayments->record(
            $request->safe()->except('confirm_duplicate'),
            $actor,
            (bool) $request->boolean('confirm_duplicate'),
        );

        return ApiResponse::created(
            new RepaymentResource($repayment->load(self::RELATIONS)),
            "{$repayment->repayment_reference} recorded.",
        );
    }

    public function verify(VerifyRepaymentRequest $request, Repayment $repayment): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->repayments->verify($repayment, $request->validated('notes'), $actor);

        return ApiResponse::success(
            new RepaymentResource($updated->load(self::RELATIONS)),
            "{$updated->repayment_reference} verified.",
        );
    }

    public function reject(RejectRepaymentRequest $request, Repayment $repayment): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->repayments->reject($repayment, $request->validated('reason'), $actor);

        return ApiResponse::success(
            new RepaymentResource($updated->load(self::RELATIONS)),
            "{$updated->repayment_reference} rejected.",
        );
    }

    /**
     * Allocates the repayment against the loan's schedule and posts the
     * ledger entry. Subject to maker-checker against whoever verified it.
     */
    public function approve(Request $request, Repayment $repayment): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->repayments->approve($repayment, $actor);

        return ApiResponse::success(
            new RepaymentResource($updated->load([...self::RELATIONS, 'allocations.scheduleEntry'])),
            "{$updated->repayment_reference} approved.",
        );
    }

    public function reverse(ReverseRepaymentRequest $request, Repayment $repayment): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->repayments->reverse($repayment, $request->validated('reason'), $actor);

        return ApiResponse::success(
            new RepaymentResource($updated->load(self::RELATIONS)),
            "{$updated->repayment_reference} reversed.",
        );
    }
}
