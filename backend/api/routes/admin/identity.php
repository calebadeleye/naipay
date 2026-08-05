<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\AuthenticationController;
use App\Domains\Identity\Http\Controllers\PasswordController;
use App\Domains\Identity\Http\Controllers\SessionController;
use App\Domains\Identity\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity — authentication and account security
|--------------------------------------------------------------------------
|
| Mounted under /api/v1/admin.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {

    /*
     * Unauthenticated. Throttled hard: these are the only endpoints reachable
     * without a token, so they are where credential-guessing arrives.
     */
    Route::middleware('throttle:authentication')->group(function (): void {
        Route::post('login', [AuthenticationController::class, 'login'])->name('login');

        Route::post('two-factor/challenge', [AuthenticationController::class, 'twoFactorChallenge'])
            ->name('two-factor.challenge');

        Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('password.forgot');
        Route::post('reset-password', [PasswordController::class, 'reset'])->name('password.reset');
    });

    /*
     * Authenticated, but deliberately NOT behind `security.steps`.
     *
     * An operator who must change their password or enrol in two-factor has to
     * be able to reach the endpoints that let them do it, and to see who they
     * are and sign out.
     */
    Route::middleware(['auth:staff', 'session.expiry'])->group(function (): void {
        Route::get('me', [AuthenticationController::class, 'me'])->name('me');
        Route::post('logout', [AuthenticationController::class, 'logout'])->name('logout');

        // Throttled the same as sign-in: the one place a stolen session,
        // without the password, gets unlimited guesses otherwise.
        Route::post('reauthenticate', [AuthenticationController::class, 'reauthenticate'])
            ->middleware('throttle:authentication')
            ->name('reauthenticate');

        Route::post('password', [PasswordController::class, 'change'])->name('password.change');

        Route::prefix('two-factor')->name('two-factor.')->group(function (): void {
            Route::get('/', [TwoFactorController::class, 'status'])->name('status');
            Route::post('enrol', [TwoFactorController::class, 'enrol'])->name('enrol');
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
            Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
            Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
                ->name('recovery-codes');
        });
    });
});

/*
 * The operator's own sessions and login history. Behind the full middleware
 * stack — there is no reason to review sessions before completing setup.
 */
Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('account')
    ->name('account.')
    ->group(function (): void {
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])
            ->whereNumber('session')
            ->name('sessions.destroy');

        Route::get('login-history', [SessionController::class, 'loginHistory'])->name('login-history');
    });
