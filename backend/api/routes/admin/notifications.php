<?php

declare(strict_types=1);

use App\Domains\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| A personal inbox, not a domain resource — every action here is implicitly
| scoped to the authenticated staff member, so there is no `permission:`
| gate the way every other admin route file has. Any authenticated staff
| member may read and clear their own notifications.
|
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])
            ->name('index');

        Route::post('{notification}/read', [NotificationController::class, 'markRead'])
            ->whereNumber('notification')
            ->name('read');

        Route::post('read-all', [NotificationController::class, 'markAllRead'])
            ->name('read-all');
    });
