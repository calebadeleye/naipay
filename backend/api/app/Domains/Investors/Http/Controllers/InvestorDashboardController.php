<?php

declare(strict_types=1);

namespace App\Domains\Investors\Http\Controllers;

use App\Domains\Investors\Services\InvestorDashboardService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class InvestorDashboardController
{
    public function __construct(
        private readonly InvestorDashboardService $dashboard,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->dashboard->summary(), 'Investor dashboard retrieved.');
    }
}
