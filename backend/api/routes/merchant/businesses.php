<?php

declare(strict_types=1);

use App\Domains\Businesses\Http\Controllers\Merchant\BusinessController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant's own businesses
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::prefix('businesses')->name('businesses.')->group(function (): void {
        Route::get('/', [BusinessController::class, 'index'])->name('index');
        Route::get('{business}', [BusinessController::class, 'show'])->whereNumber('business')->name('show');
        Route::patch('{business}', [BusinessController::class, 'update'])->whereNumber('business')->name('update');
    });
});
