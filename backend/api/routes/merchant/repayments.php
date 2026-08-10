<?php

declare(strict_types=1);

use App\Domains\Merchants\Http\Controllers\Merchant\RepaymentSummaryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant repayment summary
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. Read-only, like an invoice — see
| RepaymentSummaryController's docblock for why there is no "pay now" here.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::get('repayments/summary', RepaymentSummaryController::class)->name('repayments.summary');
});
