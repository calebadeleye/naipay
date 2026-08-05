<?php

declare(strict_types=1);

namespace App\Domains\Reports\Http\Controllers;

use App\Domains\Reports\Services\ReportService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
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
