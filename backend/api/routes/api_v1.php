<?php

declare(strict_types=1);

use App\Support\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Naipay API v1
|--------------------------------------------------------------------------
|
| Mounted at /api/v1. Administrative, investor and merchant surfaces are
| separated by prefix from the outset — each is backed by its own Sanctum
| guard (see config/auth.php) so a token issued for one can never satisfy
| another, no matter how routing evolves:
|
|   /api/v1/admin/...     internal staff (this release)
|   /api/v1/investor/...  read-only investor dashboard (this release)
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

Route::prefix('investor')->name('investor.')->group(function (): void {
    foreach (glob(__DIR__.'/investor/*.php') ?: [] as $routeFile) {
        require $routeFile;
    }
});
