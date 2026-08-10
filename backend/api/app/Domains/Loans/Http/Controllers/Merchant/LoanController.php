<?php

declare(strict_types=1);

namespace App\Domains\Loans\Http\Controllers\Merchant;

use App\Domains\Loans\Http\Resources\LoanResource;
use App\Domains\Loans\Models\Loan;
use App\Domains\Loans\Services\LoanScheduleExportService;
use App\Domains\Merchants\Models\Merchant;
use App\Support\Http\ApiResponse;
use App\Support\Query\FilterType;
use App\Support\Query\QueryPipeline;
use App\Support\Query\QuerySpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A merchant viewing their own loans through the self-service portal.
 *
 * Read-only: a merchant never approves, disburses or writes off their own
 * loan — those stay staff-only workflows, unrelated to this controller.
 */
final class LoanController
{
    private const RELATIONS = ['business', 'loanProduct', 'disbursementBankAccount'];

    public function __construct(
        private readonly LoanScheduleExportService $scheduleExport,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        $specification = QuerySpecification::make(
            searchable: ['loan_reference'],
            filters: [
                'status' => FilterType::In,
                'business_id' => FilterType::Exact,
            ],
            sortable: ['loan_reference', 'status', 'created_at'],
            defaultSort: ['-created_at'],
        );

        $query = Loan::query()->where('merchant_id', $merchant->id)->with(['business', 'loanProduct']);

        $loans = QueryPipeline::for($request, $specification)->paginate($query);

        return ApiResponse::paginated(
            $loans->through(fn (Loan $loan) => new LoanResource($loan)),
            message: 'Loans retrieved.',
        );
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authoriseOwnership($request, $loan);

        return ApiResponse::success(
            new LoanResource($loan->load([...self::RELATIONS, 'scheduleEntries'])),
            'Loan retrieved.',
        );
    }

    /**
     * Streams the repayment schedule as a PDF for download — reuses
     * LoanScheduleExportService unchanged; it takes no actor.
     */
    public function schedulePdf(Request $request, Loan $loan): Response
    {
        $this->authoriseOwnership($request, $loan);

        $pdf = $this->scheduleExport->render($loan);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->scheduleExport->filename($loan).'"',
        ]);
    }

    private function authoriseOwnership(Request $request, Loan $loan): void
    {
        /** @var Merchant $merchant */
        $merchant = $request->user();

        abort_unless(
            $loan->merchant_id === $merchant->id,
            404,
            'The requested loan was not found.',
        );
    }
}
