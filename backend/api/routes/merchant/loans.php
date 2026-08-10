<?php

declare(strict_types=1);

use App\Domains\Loans\Http\Controllers\Merchant\LoanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant's own loans
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. Read-only: a merchant never approves,
| disburses or writes off their own loan.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::prefix('loans')->name('loans.')->group(function (): void {
        Route::get('/', [LoanController::class, 'index'])->name('index');
        Route::get('{loan}', [LoanController::class, 'show'])->whereNumber('loan')->name('show');
        Route::get('{loan}/schedule/pdf', [LoanController::class, 'schedulePdf'])->whereNumber('loan')->name('schedule-pdf');
    });
});
