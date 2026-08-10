<?php

declare(strict_types=1);

use App\Domains\Merchants\Http\Controllers\MerchantAuthenticationController;
use App\Domains\Merchants\Http\Controllers\MerchantPasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant authentication
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {

    /*
     * Unauthenticated. Throttled hard, same as staff and investor sign-in —
     * these are the only endpoints reachable without a token.
     */
    Route::middleware('throttle:authentication')->group(function (): void {
        Route::post('login', [MerchantAuthenticationController::class, 'login'])->name('login');

        Route::post('activate/request', [MerchantPasswordController::class, 'requestActivation'])
            ->name('activate.request');
        Route::post('activate', [MerchantPasswordController::class, 'activate'])->name('activate');

        Route::post('forgot-password', [MerchantPasswordController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [MerchantPasswordController::class, 'reset'])->name('password.reset');
    });

    Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
        Route::get('me', [MerchantAuthenticationController::class, 'me'])->name('me');
        Route::post('logout', [MerchantAuthenticationController::class, 'logout'])->name('logout');
    });
});
