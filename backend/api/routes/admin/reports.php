<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard and reports
|--------------------------------------------------------------------------
|
| Entirely read-only: every figure here is derived from data another domain
| already owns. The trial balance sums journal_entries directly rather than
| the account_balances cache, so it is trustworthy for a past date, not only
| today.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->group(function (): void {
        Route::get('dashboard', [ReportController::class, 'dashboard'])
            ->middleware('permission:'.Permission::DashboardView->value)
            ->name('dashboard');

        Route::prefix('reports')
            ->name('reports.')
            ->group(function (): void {
                Route::get('trial-balance', [ReportController::class, 'trialBalance'])
                    ->middleware('permission:'.Permission::ReportsFinancial->value)
                    ->name('trial-balance');

                Route::get('loan-portfolio', [ReportController::class, 'loanPortfolio'])
                    ->middleware('permission:'.Permission::ReportsView->value)
                    ->name('loan-portfolio');

                Route::get('collections', [ReportController::class, 'collections'])
                    ->middleware('permission:'.Permission::ReportsView->value)
                    ->name('collections');

                Route::get('delinquency', [ReportController::class, 'delinquency'])
                    ->middleware('permission:'.Permission::ReportsView->value)
                    ->name('delinquency');
            });
    });
