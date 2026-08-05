<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Controllers;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Http\Requests\DisburseLoanRequest;
use App\Domains\Loans\Http\Requests\WriteOffLoanRequest;
use App\Domains\Loans\Http\Resources\LoanResource;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Services\LoanDisbursementService;
use App\Domains\Loans\Services\LoanService;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class LoanController
{
    private const RELATIONS = ['merchant', 'business', 'loanProduct', 'branch', 'createdBy', 'approvedBy', 'disbursementBankAccount', 'disbursedBy', 'writtenOffBy'];

    public function __construct(
        private readonly LoanService $loans,
        private readonly LoanDisbursementService $disbursements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['loan_reference'],
            filters: [
                'status' => FilterType::In,
                'merchant_id' => FilterType::Exact,
                'business_id' => FilterType::Exact,
                'loan_product_id' => FilterType::Exact,
                'branch_id' => FilterType::Exact,
                'disbursement_date' => FilterType::DateRange,
                'created_at' => FilterType::DateRange,
            ],
            sortable: ['loan_reference', 'status', 'principal_amount', 'disbursement_date', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $query = Loan::query()->visibleTo($actor)->with(['merchant', 'business', 'loanProduct']);

        $loans = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $loans->through(fn (Loan $loan) => new LoanResource($loan)),
            message: 'Loans retrieved.',
        );
    }

    public function show(Loan $loan): JsonResponse
    {
        return ApiResponse::success(
            new LoanResource($loan->load([...self::RELATIONS, 'scheduleEntries'])),
            'Loan retrieved.',
        );
    }

    /**
     * The second sign-off: confirms the loan contract is correct and ready
     * to fund. Subject to maker-checker against whoever's application
     * approval created it.
     */
    public function approve(Request $request, Loan $loan): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->loans->approve($loan, $actor);

        return ApiResponse::success(
            new LoanResource($updated->load(self::RELATIONS)),
            "{$updated->loan_reference} is approved and ready for disbursement.",
        );
    }

    /**
     * Releases the loan's funds, builds its repayment schedule and posts the
     * ledger entry. Subject to maker-checker against whoever approved it.
     */
    public function disburse(DisburseLoanRequest $request, Loan $loan): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        /** @var BankAccount $bankAccount */
        $bankAccount = BankAccount::query()->findOrFail($request->validated('bank_account_id'));

        $disbursementDate = $request->validated('disbursement_date') !== null
            ? Carbon::parse($request->validated('disbursement_date'))
            : null;

        $updated = $this->disbursements->disburse($loan, $bankAccount, $actor, $disbursementDate);

        return ApiResponse::success(
            new LoanResource($updated->load([...self::RELATIONS, 'scheduleEntries'])),
            "{$updated->loan_reference} disbursed.",
        );
    }

    /**
     * Writes off a disbursed loan's outstanding principal. There is no
     * delete: every disbursement on record names a loan permanently.
     */
    public function writeOff(WriteOffLoanRequest $request, Loan $loan): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->loans->writeOff($loan, $request->validated('reason'), $actor);

        return ApiResponse::success(
            new LoanResource($updated->load(self::RELATIONS)),
            "{$updated->loan_reference} has been written off.",
        );
    }
}
