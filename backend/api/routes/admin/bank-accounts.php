<?php

declare(strict_types=1);

use App\Domains\Accounts\Http\Controllers\BankAccountController;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Designated bank accounts
|--------------------------------------------------------------------------
|
| Naipay's own accounts — where repayments are collected and loans are
| disbursed from. Every change is maker-checked: bank_account.change is
| enforced centrally by MakerCheckerGuard on the approve step, and every
| mutation is audited without exception.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('bank-accounts')
    ->name('bank-accounts.')
    ->group(function (): void {
        Route::get('/', [BankAccountController::class, 'index'])
            ->middleware('permission:'.Permission::BankAccountsView->value)
            ->name('index');

        Route::get('options', [BankAccountController::class, 'options'])
            ->middleware('permission:'.Permission::BankAccountsView->value)
            ->name('options');

        Route::post('/', [BankAccountController::class, 'store'])
            ->middleware('permission:'.Permission::BankAccountsManage->value)
            ->name('store');

        Route::get('{account}', [BankAccountController::class, 'show'])
            ->middleware('permission:'.Permission::BankAccountsView->value)
            ->whereNumber('account')
            ->name('show');

        Route::patch('{account}', [BankAccountController::class, 'update'])
            ->middleware('permission:'.Permission::BankAccountsManage->value)
            ->whereNumber('account')
            ->name('update');

        Route::post('{account}/approve', [BankAccountController::class, 'approve'])
            ->middleware('permission:'.Permission::BankAccountsApprove->value)
            ->whereNumber('account')
            ->name('approve');

        Route::post('{account}/default', [BankAccountController::class, 'setDefault'])
            ->middleware('permission:'.Permission::BankAccountsManage->value)
            ->whereNumber('account')
            ->name('default');

        // Suspend, close or reactivate. There is no delete: every repayment
        // and disbursement on record names one of these permanently.
        Route::post('{account}/status', [BankAccountController::class, 'changeStatus'])
            ->middleware('permission:'.Permission::BankAccountsManage->value)
            ->whereNumber('account')
            ->name('status');
    });
