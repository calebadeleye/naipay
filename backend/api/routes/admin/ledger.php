<?php

declare(strict_types=1);

use App\Domains\Identity\Enums\Permission;
use App\Domains\Ledger\Http\Controllers\LedgerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ledger
|--------------------------------------------------------------------------
|
| A raw view of the journal — enough to verify a posting or trace one back to
| what caused it. Trial balance, the ledger report and reconciliation belong
| to their own phases.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('ledger')
    ->name('ledger.')
    ->group(function (): void {
        Route::get('/', [LedgerController::class, 'index'])
            ->middleware('permission:'.Permission::LedgerView->value)
            ->name('index');

        Route::get('accounts', [LedgerController::class, 'accounts'])
            ->middleware('permission:'.Permission::LedgerView->value)
            ->name('accounts');

        Route::get('{transaction}', [LedgerController::class, 'show'])
            ->middleware('permission:'.Permission::LedgerView->value)
            ->whereNumber('transaction')
            ->name('show');

        Route::post('{transaction}/reverse', [LedgerController::class, 'reverse'])
            ->middleware('permission:'.Permission::LedgerReverse->value)
            ->whereNumber('transaction')
            ->name('reverse');
    });
