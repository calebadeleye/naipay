<?php

declare(strict_types=1);

use App\Domains\Documents\Http\Controllers\DocumentController;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Documents and KYC
|--------------------------------------------------------------------------
|
| Files are never served from a public path. Every retrieval passes through
| authentication, the permission check, the branch scope and the audit trail.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])->group(function (): void {

    Route::prefix('documents')->name('documents.')->group(function (): void {
        // Applicable types for an upload picker: merchant, business, loan,
        // guarantor, collateral, repayment.
        Route::get('types/{owner}', [DocumentController::class, 'types'])
            ->middleware('permission:'.Permission::DocumentsView->value)
            ->name('types');

        Route::get('{document}', [DocumentController::class, 'show'])
            ->middleware('permission:'.Permission::DocumentsView->value)
            ->whereNumber('document')
            ->name('show');

        Route::get('{document}/download', [DocumentController::class, 'download'])
            ->middleware('permission:'.Permission::DocumentsDownload->value)
            ->whereNumber('document')
            ->name('download');

        Route::post('{document}/verify', [DocumentController::class, 'verify'])
            ->middleware('permission:'.Permission::DocumentsVerify->value)
            ->whereNumber('document')
            ->name('verify');

        Route::post('{document}/reject', [DocumentController::class, 'reject'])
            ->middleware('permission:'.Permission::DocumentsVerify->value)
            ->whereNumber('document')
            ->name('reject');
    });

    Route::prefix('merchants/{merchant}')->name('merchants.')->whereNumber('merchant')->group(function (): void {
        Route::get('documents', [DocumentController::class, 'indexForMerchant'])
            ->middleware('permission:'.Permission::DocumentsView->value)
            ->name('documents.index');

        Route::post('documents', [DocumentController::class, 'storeForMerchant'])
            ->middleware('permission:'.Permission::DocumentsUpload->value)
            ->name('documents.store');

        Route::get('documents/outstanding', [DocumentController::class, 'outstandingForMerchant'])
            ->middleware('permission:'.Permission::DocumentsView->value)
            ->name('documents.outstanding');
    });

    Route::prefix('businesses/{business}')->name('businesses.')->whereNumber('business')->group(function (): void {
        Route::get('documents', [DocumentController::class, 'indexForBusiness'])
            ->middleware('permission:'.Permission::DocumentsView->value)
            ->name('documents.index');

        Route::post('documents', [DocumentController::class, 'storeForBusiness'])
            ->middleware('permission:'.Permission::DocumentsUpload->value)
            ->name('documents.store');
    });
});
