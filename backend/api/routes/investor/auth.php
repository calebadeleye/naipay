<?php

declare(strict_types=1);

use App\Domains\Investors\Http\Controllers\InvestorAuthenticationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Investor authentication
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/investor.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::middleware('throttle:authentication')->group(function (): void {
        Route::post('login', [InvestorAuthenticationController::class, 'login'])->name('login');
    });

    Route::middleware(['auth:investor', 'session.expiry'])->group(function (): void {
        Route::get('me', [InvestorAuthenticationController::class, 'me'])->name('me');
        Route::post('logout', [InvestorAuthenticationController::class, 'logout'])->name('logout');
    });
});
