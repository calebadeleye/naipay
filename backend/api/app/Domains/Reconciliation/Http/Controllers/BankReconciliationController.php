<?php

declare(strict_types=1);

namespace App\Domains\Reconciliation\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Models\Loan;
use App\Domains\Reconciliation\Http\Requests\ExcludeBankStatementLineRequest;
use App\Domains\Reconciliation\Http\Requests\MatchBankStatementLineRequest;
use App\Domains\Reconciliation\Http\Requests\OpenReconciliationRequest;
use App\Domains\Reconciliation\Http\Requests\StoreBankStatementLineRequest;
use App\Domains\Reconciliation\Http\Resources\BankReconciliationResource;
use App\Domains\Reconciliation\Http\Resources\BankStatementLineResource;
use App\Domains\Reconciliation\Models\BankReconciliation;
use App\Domains\Reconciliation\Models\BankStatementLine;
use App\Domains\Reconciliation\Services\ReconciliationService;
use App\Domains\Repayments\Models\Repayment;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankReconciliationController
{
    private const RELATIONS = ['bankAccount', 'preparedBy', 'approvedBy'];

    /**
     * The short discriminator the API accepts maps to the FQCN stored on
     * `matched_to_type` — clients never need to know the internal class name.
     *
     * @var array<string, string>
     */
    private const MATCH_TYPES = [
        'repayment' => Repayment::class,
        'loan' => Loan::class,
    ];

    public function __construct(
        private readonly ReconciliationService $reconciliations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: [],
            filters: [
                'status' => FilterType::In,
                'bank_account_id' => FilterType::Exact,
            ],
            sortable: ['period_start', 'period_end', 'status', 'created_at'],
            defaultSort: ['-period_start'],
        );

        $query = BankReconciliation::query()->with(['bankAccount']);

        $reconciliations = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $reconciliations->through(fn (BankReconciliation $r) => new BankReconciliationResource($r)),
            message: 'Reconciliations retrieved.',
        );
    }

    public function store(OpenReconciliationRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $reconciliation = $this->reconciliations->open($request->validated(), $actor);

        return ApiResponse::created(
            new BankReconciliationResource($reconciliation->load(self::RELATIONS)),
            'Reconciliation opened.',
        );
    }

    public function show(BankReconciliation $reconciliation): JsonResponse
    {
        return ApiResponse::success(
            new BankReconciliationResource($reconciliation->load([...self::RELATIONS, 'lines'])),
            'Reconciliation retrieved.',
        );
    }

    public function addLine(StoreBankStatementLineRequest $request, BankReconciliation $reconciliation): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $line = $this->reconciliations->addLine($reconciliation, $request->validated(), $actor);

        return ApiResponse::created(new BankStatementLineResource($line), 'Statement line added.');
    }

    public function suggestions(BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        $matches = $this->reconciliations->suggestMatches($line);

        return ApiResponse::success(
            $matches->map(fn ($record): array => [
                'id' => $record->id,
                'reference' => $record instanceof Repayment ? $record->repayment_reference : $record->loan_reference,
            ])->all(),
            'Candidate matches retrieved.',
        );
    }

    public function matchLine(MatchBankStatementLineRequest $request, BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $type = self::MATCH_TYPES[$request->validated('matched_to_type')];

        $updated = $this->reconciliations->match($line, $type, (int) $request->validated('matched_to_id'), $actor);

        return ApiResponse::success(new BankStatementLineResource($updated), 'Statement line matched.');
    }

    public function unmatchLine(Request $request, BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->reconciliations->unmatch($line, $actor);

        return ApiResponse::success(new BankStatementLineResource($updated), 'Statement line unmatched.');
    }

    public function excludeLine(ExcludeBankStatementLineRequest $request, BankReconciliation $reconciliation, BankStatementLine $line): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->reconciliations->exclude($line, $request->validated('reason'), $actor);

        return ApiResponse::success(new BankStatementLineResource($updated), 'Statement line excluded.');
    }

    public function submit(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->reconciliations->submit($reconciliation, $actor);

        return ApiResponse::success(
            new BankReconciliationResource($updated->load(self::RELATIONS)),
            'Reconciliation submitted for approval.',
        );
    }

    /**
     * Signs off that the period is genuinely settled. Subject to
     * maker-checker against whoever matched the statement's lines.
     */
    public function approve(Request $request, BankReconciliation $reconciliation): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->reconciliations->approve($reconciliation, $actor);

        return ApiResponse::success(
            new BankReconciliationResource($updated->load(self::RELATIONS)),
            'Reconciliation approved.',
        );
    }
}
