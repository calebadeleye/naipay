<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Receipts\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Receipts
|--------------------------------------------------------------------------
|
| Read-only: a receipt is generated automatically the moment a repayment is
| approved (RepaymentService::approve()) and never created or edited here.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('receipts')
    ->name('receipts.')
    ->group(function (): void {
        Route::get('/', [ReceiptController::class, 'index'])
            ->middleware('permission:'.Permission::RepaymentsView->value)
            ->name('index');

        Route::get('{receipt}', [ReceiptController::class, 'show'])
            ->middleware('permission:'.Permission::RepaymentsView->value)
            ->whereNumber('receipt')
            ->name('show');
    });
