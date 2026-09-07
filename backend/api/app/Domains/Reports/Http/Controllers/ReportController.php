<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Identity\Models\Staff;
use App\Domains\Reports\Data\PortfolioFilter;
use App\Domains\Reports\Http\Requests\PortfolioAnalyticsExportRequest;
use App\Domains\Reports\Http\Requests\PortfolioAnalyticsRequest;
use App\Domains\Reports\Services\LoanPortfolioAnalyticsService;
use App\Domains\Reports\Services\ReportService;
use App\Domains\Reports\Support\PortfolioAnalyticsCsv;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly LoanPortfolioAnalyticsService $portfolioAnalytics,
    ) {}

    public function dashboard(): JsonResponse
    {
        return ApiResponse::success($this->reports->dashboard(), 'Dashboard summary retrieved.');
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $asOf = $request->filled('as_of') ? Carbon::parse($request->string('as_of')->toString()) : null;

        return ApiResponse::success($this->reports->trialBalance($asOf), 'Trial balance retrieved.');
    }

    public function loanPortfolio(): JsonResponse
    {
        return ApiResponse::success($this->reports->loanPortfolio(), 'Loan portfolio retrieved.');
    }

    /**
     * The full portfolio analytics payload — KPIs, risk, ageing, collections,
     * disbursements, and every breakdown — for one set of filters, computed in
     * the backend and scoped to what the acting staff member may see.
     */
    public function portfolioAnalytics(PortfolioAnalyticsRequest $request): JsonResponse
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $filter = PortfolioFilter::fromRequest($request);

        return ApiResponse::success(
            $this->portfolioAnalytics->analyse($filter, $actor),
            'Loan portfolio analytics retrieved.',
        );
    }

    /**
     * The same analytics payload, for the same filters, as a CSV download —
     * built from the identical analyse() result the dashboard renders, never a
     * parallel calculation. `?section=` selects the slice (summary, or one of
     * the breakdown tables); it defaults to the full summary.
     */
    public function portfolioAnalyticsExport(PortfolioAnalyticsExportRequest $request, PortfolioAnalyticsCsv $csv): Response
    {
        /** @var Staff $actor */
        $actor = $request->user();

        $analytics = $this->portfolioAnalytics->analyse(PortfolioFilter::fromRequest($request), $actor);
        $section = $request->section();

        return response($csv->build($analytics, $section), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$csv->filename($analytics, $section).'"',
        ]);
    }

    public function collections(Request $request): JsonResponse
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : now()->endOfMonth();

        return ApiResponse::success($this->reports->collections($from, $to), 'Collections report retrieved.');
    }

    public function delinquency(): JsonResponse
    {
        return ApiResponse::success($this->reports->delinquency(), 'Delinquency report retrieved.');
    }

    public function complianceOverview(): JsonResponse
    {
        return ApiResponse::success($this->reports->complianceOverview(), 'Compliance overview retrieved.');
    }
}
