<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\LoanProducts\Http\Controllers\LoanProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loan products
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('loan-products')
    ->name('loan-products.')
    ->group(function (): void {
        Route::get('/', [LoanProductController::class, 'index'])
            ->middleware('permission:'.Permission::LoanProductsView->value)
            ->name('index');

        Route::get('options', [LoanProductController::class, 'options'])
            ->middleware('permission:'.Permission::LoanProductsView->value)
            ->name('options');

        Route::post('/', [LoanProductController::class, 'store'])
            ->middleware('permission:'.Permission::LoanProductsManage->value)
            ->name('store');

        Route::get('{product}', [LoanProductController::class, 'show'])
            ->middleware('permission:'.Permission::LoanProductsView->value)
            ->whereNumber('product')
            ->name('show');

        /*
         * Prices a hypothetical loan and returns the full schedule, so the
         * merchant is told exactly what they will repay and on which dates
         * before agreeing to anything.
         */
        Route::post('{product}/preview', [LoanProductController::class, 'preview'])
            ->middleware('permission:'.Permission::LoanProductsView->value)
            ->whereNumber('product')
            ->name('preview');

        Route::patch('{product}', [LoanProductController::class, 'update'])
            ->middleware('permission:'.Permission::LoanProductsManage->value)
            ->whereNumber('product')
            ->name('update');

        // Retire or reactivate. There is no delete: loans booked under a
        // product must keep pointing at the terms they were sold on.
        Route::post('{product}/status', [LoanProductController::class, 'changeStatus'])
            ->middleware('permission:'.Permission::LoanProductsManage->value)
            ->whereNumber('product')
            ->name('status');
    });
