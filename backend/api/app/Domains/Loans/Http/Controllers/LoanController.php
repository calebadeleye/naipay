<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Controllers;

use App\Domains\Accounts\Models\BankAccount;
use App\Domains\Identity\Models\Staff;
use App\Domains\Loans\Http\Requests\DisburseLoanRequest;
use App\Domains\Loans\Http\Requests\RescheduleLoanRequest;
use App\Domains\Loans\Http\Requests\WriteOffLoanRequest;
use App\Domains\Loans\Http\Resources\LoanResource;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Notifications\LoanScheduleNotification;
use App\Domains\Loans\Services\LoanDisbursementService;
use App\Domains\Loans\Services\LoanRescheduleService;
use App\Domains\Loans\Services\LoanScheduleExportService;
use App\Domains\Loans\Services\LoanService;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

final class LoanController
{
    private const RELATIONS = ['merchant.account', 'business', 'loanProduct', 'branch', 'createdBy', 'approvedBy', 'disbursementBankAccount', 'disbursedBy', 'writtenOffBy'];

    public function __construct(
        private readonly LoanService $loans,
        private readonly LoanDisbursementService $disbursements,
        private readonly LoanScheduleExportService $scheduleExport,
        private readonly LoanRescheduleService $reschedules,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['loan_reference', 'merchant.first_name', 'merchant.last_name', 'merchant.account.account_number'],
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

        $query = Loan::query()->visibleTo($actor)->with(['merchant.account', 'business', 'loanProduct']);

        // Loan officer and days-past-due are not columns on `loans`, so they
        // sit outside the column-driven QueryPipeline — the same relationship
        // and schedule logic the portfolio dashboard drills in on.
        if ($request->filled('loan_officer_id')) {
            $query->forOfficer((int) $request->integer('loan_officer_id'));
        }

        $minDaysPastDue = $request->filled('min_days_past_due')
            ? max(0, (int) $request->integer('min_days_past_due'))
            : null;
        $maxDaysPastDue = $request->filled('max_days_past_due')
            ? max(0, (int) $request->integer('max_days_past_due'))
            : null;

        if ($request->boolean('overdue') || $minDaysPastDue !== null || $maxDaysPastDue !== null) {
            $query->inArrears($minDaysPastDue, $maxDaysPastDue);
        }

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
            new LoanResource($updated->load([...self::RELATIONS, 'scheduleEntries'])),
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
     * Shifts the repayment schedule to new dates — for a public holiday, or
     * at a merchant's request. Only the dates move: instalment amounts,
     * interest and the loan's totals are untouched, and no ledger entry is
     * involved.
     */
    public function reschedule(RescheduleLoanRequest $request, Loan $loan): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $updated = $this->reschedules->reschedule(
            $loan,
            Carbon::parse($request->validated('next_due_date')),
            $request->validated('reason'),
            $actor,
        );

        return ApiResponse::success(
            new LoanResource($updated->load([...self::RELATIONS, 'scheduleEntries'])),
            "{$updated->loan_reference} has been rescheduled.",
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
            new LoanResource($updated->load([...self::RELATIONS, 'scheduleEntries'])),
            "{$updated->loan_reference} has been written off.",
        );
    }

    /**
     * Streams the repayment schedule as a PDF for download.
     */
    public function schedulePdf(Loan $loan): Response
    {
        $pdf = $this->scheduleExport->render($loan);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->scheduleExport->filename($loan).'"',
        ]);
    }

    /**
     * Emails the repayment schedule PDF to the merchant on file.
     */
    public function emailSchedule(Loan $loan): JsonResponse
    {
        $loan->loadMissing('merchant.account');

        if ($loan->merchant === null || $loan->merchant->email === null) {
            throw new DomainException('This merchant has no email address on file.');
        }

        $pdf = $this->scheduleExport->render($loan);

        $loan->merchant->notify(new LoanScheduleNotification($loan, $pdf, $this->scheduleExport->filename($loan)));

        return ApiResponse::success(
            null,
            "The repayment schedule has been emailed to {$loan->merchant->email}.",
        );
    }
}
