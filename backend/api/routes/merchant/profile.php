<?php

declare(strict_types=1);

use App\Domains\Merchants\Http\Controllers\Merchant\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant profile
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. Ownership is implicit — the subject is
| always the authenticated merchant — so there is no permission system here,
| the same way the investor dashboard has none.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
});
