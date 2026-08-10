<?php

declare(strict_types=1);

use App\Domains\Documents\Http\Controllers\Merchant\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Merchant document upload
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/merchant. Only merchant and business owner types are
| reachable here; verification stays staff-only.
|
*/

Route::middleware(['auth:merchant', 'session.expiry'])->group(function (): void {
    Route::get('documents/types/{owner}', [DocumentController::class, 'types'])->name('documents.types');

    Route::get('documents', [DocumentController::class, 'indexForSelf'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'storeForSelf'])->name('documents.store');

    Route::get('businesses/{business}/documents', [DocumentController::class, 'indexForBusiness'])
        ->whereNumber('business')
        ->name('businesses.documents.index');
    Route::post('businesses/{business}/documents', [DocumentController::class, 'storeForBusiness'])
        ->whereNumber('business')
        ->name('businesses.documents.store');
});
