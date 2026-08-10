<?php

declare(strict_types=1);

use App\Domains\Receipts\Http\Controllers\Merchant\ReceiptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant's own receipts
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::prefix('receipts')->name('receipts.')->group(function (): void {
        Route::get('/', [ReceiptController::class, 'index'])->name('index');
        Route::get('{receipt}', [ReceiptController::class, 'show'])->whereNumber('receipt')->name('show');
    });
});
