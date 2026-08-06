<?php

declare(strict_types=1);

use App\Domains\Investors\Http\Controllers\InvestorDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Investor dashboard
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/investor. A single read-only view — an investor
| account has no other endpoint to reach.
|
*/

Route::middleware(['auth:investor', 'session.expiry'])->group(function (): void {
    Route::get('dashboard', [InvestorDashboardController::class, 'index'])->name('dashboard');
});
