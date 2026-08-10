<?php

declare(strict_types=1);

use App\Domains\LoanProducts\Http\Controllers\Merchant\LoanProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan products available to apply for
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. Read-only — see the controller docblock.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::get('loan-products', [LoanProductController::class, 'index'])->name('loan-products.index');
});
