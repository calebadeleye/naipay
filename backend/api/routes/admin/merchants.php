<?php

declare(strict_types=1);

use App\Domains\Businesses\Http\Controllers\BusinessController;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Merchants\Http\Controllers\MerchantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchants and their businesses
|--------------------------------------------------------------------------
|
| The onboarding workflow is expressed as distinct action endpoints rather
| than a status field on a general update. Each carries its own permission,
| most require a recorded reason, and approval is maker-checked — none of
| which survives being folded into a PATCH.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])->group(function (): void {

    Route::prefix('merchants')->name('merchants.')->group(function (): void {
        Route::get('/', [MerchantController::class, 'index'])
            ->middleware('permission:'.Permission::MerchantsView->value)
            ->name('index');

        Route::post('/', [MerchantController::class, 'store'])
            ->middleware('permission:'.Permission::MerchantsCreate->value)
            ->name('store');

        Route::get('{merchant}', [MerchantController::class, 'show'])
            ->middleware('permission:'.Permission::MerchantsView->value)
            ->whereNumber('merchant')
            ->name('show');

        Route::patch('{merchant}', [MerchantController::class, 'update'])
            ->middleware('permission:'.Permission::MerchantsUpdate->value)
            ->whereNumber('merchant')
            ->name('update');

        // --- Onboarding workflow ------------------------------------------
        Route::post('{merchant}/submit', [MerchantController::class, 'submit'])
            ->middleware('permission:'.Permission::MerchantsUpdate->value)
            ->whereNumber('merchant')
            ->name('submit');

        // Compliance confirms identity and documents.
        Route::post('{merchant}/verify', [MerchantController::class, 'verify'])
            ->middleware('permission:'.Permission::KycVerify->value)
            ->whereNumber('merchant')
            ->name('verify');

        Route::post('{merchant}/approve', [MerchantController::class, 'approve'])
            ->middleware('permission:'.Permission::MerchantsApprove->value)
            ->whereNumber('merchant')
            ->name('approve');

        Route::post('{merchant}/reject', [MerchantController::class, 'reject'])
            ->middleware('permission:'.Permission::MerchantsApprove->value)
            ->whereNumber('merchant')
            ->name('reject');

        Route::post('{merchant}/return-to-draft', [MerchantController::class, 'returnToDraft'])
            ->middleware('permission:'.Permission::MerchantsUpdate->value)
            ->whereNumber('merchant')
            ->name('return-to-draft');

        Route::post('{merchant}/suspend', [MerchantController::class, 'suspend'])
            ->middleware('permission:'.Permission::MerchantsSuspend->value)
            ->whereNumber('merchant')
            ->name('suspend');

        Route::post('{merchant}/reinstate', [MerchantController::class, 'reinstate'])
            ->middleware('permission:'.Permission::MerchantsSuspend->value)
            ->whereNumber('merchant')
            ->name('reinstate');

        // --- Businesses under a merchant ----------------------------------
        Route::post('{merchant}/businesses', [BusinessController::class, 'store'])
            ->middleware('permission:'.Permission::BusinessesCreate->value)
            ->whereNumber('merchant')
            ->name('businesses.store');
    });

    Route::prefix('businesses')->name('businesses.')->group(function (): void {
        Route::get('/', [BusinessController::class, 'index'])
            ->middleware('permission:'.Permission::BusinessesView->value)
            ->name('index');

        Route::get('types', [BusinessController::class, 'typeOptions'])
            ->middleware('permission:'.Permission::BusinessesView->value)
            ->name('types');

        Route::get('{business}', [BusinessController::class, 'show'])
            ->middleware('permission:'.Permission::BusinessesView->value)
            ->whereNumber('business')
            ->name('show');

        Route::patch('{business}', [BusinessController::class, 'update'])
            ->middleware('permission:'.Permission::BusinessesUpdate->value)
            ->whereNumber('business')
            ->name('update');

        Route::post('{business}/verify', [BusinessController::class, 'verify'])
            ->middleware('permission:'.Permission::BusinessesApprove->value)
            ->whereNumber('business')
            ->name('verify');

        Route::post('{business}/reject-verification', [BusinessController::class, 'rejectVerification'])
            ->middleware('permission:'.Permission::BusinessesApprove->value)
            ->whereNumber('business')
            ->name('reject-verification');

        Route::post('{business}/status', [BusinessController::class, 'changeStatus'])
            ->middleware('permission:'.Permission::BusinessesUpdate->value)
            ->whereNumber('business')
            ->name('status');
    });
});
