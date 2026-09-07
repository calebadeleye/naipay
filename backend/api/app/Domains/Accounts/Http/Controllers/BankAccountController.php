<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Http\Controllers;

use App\Domains\Accounts\Enums\BankAccountStatus;
use App\Domains\Accounts\Http\Requests\StoreBankAccountRequest;
use App\Domains\Accounts\Http\Requests\UpdateBankAccountRequest;
use App\Domains\Accounts\Http\Resources\BankAccountResource;
use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Accounts\Services\BankAccountService;
use App\Domains\Identity\Models\Staff;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BankAccountController
{
    public function __construct(
        private readonly BankAccountService $bankAccounts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $specification = QuerySpecification::make(
            searchable: ['bank_name', 'account_name', 'account_number'],
            filters: [
                'status' => FilterType::In,
            ],
            sortable: ['bank_name', 'account_name', 'status', 'created_at'],
            defaultSort: ['bank_name'],
        );

        $query = BankAccount::query()->with(['approvedBy']);

        // `purposes` is a JSON set, so it cannot go through the pipeline's
        // column filters: an account matches when it carries any requested
        // purpose. Accepts `?purpose=a,b` or repeated `?purpose[]=`.
        $purposes = collect(
            is_array($raw = $request->input('purpose', $request->input('account_purpose', [])))
                ? $raw
                : explode(',', (string) $raw),
        )->map(fn (mixed $value): string => trim((string) $value))->filter()->values();

        if ($purposes->isNotEmpty()) {
            $query->where(function ($builder) use ($purposes): void {
                foreach ($purposes as $purpose) {
                    $builder->orWhereJsonContains('purposes', $purpose);
                }
            });
        }

        $accounts = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $accounts->through(fn (BankAccount $account) => new BankAccountResource($account)),
            message: 'Bank accounts retrieved.',
        );
    }

    /**
     * Active accounts, for pickers on the disbursement and repayment screens.
     */
    public function options(): JsonResponse
    {
        $accounts = BankAccount::query()
            ->active()
            ->orderBy('bank_name')
            ->get()
            ->map(fn (BankAccount $account): array => [
                'value' => $account->id,
                'label' => $account->label(),
                'purposes' => $account->purposeValues(),
                'is_default_collection_account' => $account->is_default_collection_account,
                'is_default_disbursement_account' => $account->is_default_disbursement_account,
            ]);

        return ApiResponse::success($accounts->all(), 'Bank account options retrieved.');
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $account = $this->bankAccounts->create($request->validated(), $actor);

        return ApiResponse::created(
            new BankAccountResource($account),
            "{$account->label()} added.",
        );
    }

    public function show(BankAccount $account): JsonResponse
    {
        return ApiResponse::success(
            new BankAccountResource($account->load('approvedBy')),
            'Bank account retrieved.',
        );
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $account): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->bankAccounts->update($account, $request->validated(), $actor);

        return ApiResponse::success(new BankAccountResource($updated), 'Bank account updated.');
    }

    /**
     * Confirms a proposed account, or a material change to one. Subject to
     * maker-checker: the officer who proposed it cannot confirm it.
     */
    public function approve(Request $request, BankAccount $account): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->bankAccounts->approve($account, $actor);

        return ApiResponse::success(
            new BankAccountResource($updated),
            "{$updated->label()} approved and ready for use.",
        );
    }

    public function setDefault(Request $request, BankAccount $account): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'which' => ['required', 'string', Rule::in(['collection', 'disbursement'])],
        ]);

        $updated = $this->bankAccounts->setAsDefault($account, $validated['which'], $actor);

        return ApiResponse::success(
            new BankAccountResource($updated),
            "{$updated->label()} is now the default {$validated['which']} account.",
        );
    }

    /**
     * Suspends, closes or reactivates an account. There is no delete: every
     * repayment and disbursement on record names one of these permanently.
     */
    public function changeStatus(Request $request, BankAccount $account): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'status' => ['required', Rule::enum(BankAccountStatus::class)],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $updated = $this->bankAccounts->changeStatus(
            $account,
            BankAccountStatus::from($validated['status']),
            $validated['reason'],
            $actor,
        );

        return ApiResponse::success(
            new BankAccountResource($updated),
            "{$updated->label()} is now {$updated->status->label()}.",
        );
    }
}
