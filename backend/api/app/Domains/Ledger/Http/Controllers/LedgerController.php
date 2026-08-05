<?php

declare(strict_types=1);

namespace App\Domains\Ledger\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Ledger\Http\Resources\JournalTransactionResource;
use App\Domains\Ledger\Http\Resources\LedgerAccountResource;
use App\Domains\Ledger\Models\JournalTransaction;
use App\Domains\Ledger\Models\LedgerAccount;
use App\Domains\Ledger\Services\LedgerPostingService;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A raw view of the journal.
 *
 * Deliberately minimal — a filterable list and a single transaction with its
 * entries, enough to verify a posting or trace one back to what caused it.
 * Trial balance, the ledger report and reconciliation are reporting
 * concerns and belong to their own phases, not to the domain that writes the
 * journal.
 */
final class LedgerController
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['transaction_reference', 'description'],
            filters: [
                'transaction_type' => FilterType::Exact,
                'source_type' => FilterType::Exact,
                'source_id' => FilterType::Exact,
                'status' => FilterType::In,
                'posting_date' => FilterType::DateRange,
            ],
            sortable: ['transaction_reference', 'posting_date', 'transaction_date', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $transactions = QueryPipeline::for($request, $specification)
            ->paginate(JournalTransaction::query()->with('createdBy'));

        return ApiResponse::paginated(
            $transactions->through(fn (JournalTransaction $t) => new JournalTransactionResource($t)),
            message: 'Journal transactions retrieved.',
        );
    }

    public function show(JournalTransaction $transaction): JsonResponse
    {
        return ApiResponse::success(
            new JournalTransactionResource($transaction->load(['entries.account', 'createdBy'])),
            'Journal transaction retrieved.',
        );
    }

    /**
     * Posts a compensating transaction. Every posted entry stays in the
     * journal permanently — this never edits or removes the original.
     */
    public function reverse(Request $request, JournalTransaction $transaction): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $reversal = $this->ledger->reverse($transaction, $validated['reason'], $actor);

        return ApiResponse::success(
            new JournalTransactionResource($reversal->load(['entries.account', 'createdBy'])),
            "Reversal {$reversal->transaction_reference} posted.",
        );
    }

    public function accounts(): JsonResponse
    {
        $accounts = LedgerAccount::query()->with('balance')->orderBy('code')->get();

        return ApiResponse::success(
            LedgerAccountResource::collection($accounts)->resolve(),
            'Chart of accounts retrieved.',
        );
    }
}
