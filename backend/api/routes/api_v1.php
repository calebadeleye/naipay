<?php

declare(strict_types=1);

use App\Support\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Naipay API v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1. Administrative and merchant surfaces are separated by
| prefix from the outset so the merchant portal and mobile application can be
| added in a later phase without restructuring this file or the admin client:
|
|   /api/v1/admin/...     internal staff (this release)
|   /api/v1/merchant/...  merchant portal and mobile app (future phase)
|
| Domain route files are registered below as each phase lands, keeping this
| file a table of contents rather than a route dump.
|
*/

Route::get('/health', HealthController::class)->name('health');

Route::prefix('admin')->name('admin.')->group(function (): void {
    foreach (glob(__DIR__.'/admin/*.php') ?: [] as $routeFile) {
        require $routeFile;
    }
});
