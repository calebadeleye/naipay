<?php

declare(strict_types=1);

use App\Domains\LoanApplications\Http\Controllers\Merchant\LoanApplicationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant loan applications
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. A new application always lands in Draft —
| an officer reviews and submits it. See LoanApplicationController's
| docblock for why.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::prefix('loan-applications')->name('loan-applications.')->group(function (): void {
        Route::get('/', [LoanApplicationController::class, 'index'])->name('index');
        Route::post('/', [LoanApplicationController::class, 'store'])->name('store');
        Route::get('{application}', [LoanApplicationController::class, 'show'])->whereNumber('application')->name('show');
        Route::post('{application}/withdraw', [LoanApplicationController::class, 'withdraw'])
            ->whereNumber('application')
            ->name('withdraw');
    });
});
