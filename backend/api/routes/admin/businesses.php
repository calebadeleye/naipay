<?php

declare(strict_types=1);

use App\Domains\Businesses\Http\Controllers\BusinessCategoryController;
use App\Domains\Identity\Enums\Permission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Businesses and business categories
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:staff', 'session.expiry', 'security.steps'])->group(function (): void {

    Route::prefix('business-categories')->name('business-categories.')->group(function (): void {
        Route::get('/', [BusinessCategoryController::class, 'index'])
            ->middleware('permission:'.Permission::CategoriesView->value)
            ->name('index');

        // Drives the searchable dropdown on the merchant onboarding form.
        Route::get('options', [BusinessCategoryController::class, 'options'])
            ->middleware('permission:'.Permission::CategoriesView->value)
            ->name('options');

        Route::post('reorder', [BusinessCategoryController::class, 'reorder'])
            ->middleware('permission:'.Permission::CategoriesManage->value)
            ->name('reorder');

        Route::post('/', [BusinessCategoryController::class, 'store'])
            ->middleware('permission:'.Permission::CategoriesManage->value)
            ->name('store');

        Route::get('{category}', [BusinessCategoryController::class, 'show'])
            ->middleware('permission:'.Permission::CategoriesView->value)
            ->whereNumber('category')
            ->name('show');

        Route::patch('{category}', [BusinessCategoryController::class, 'update'])
            ->middleware('permission:'.Permission::CategoriesManage->value)
            ->whereNumber('category')
            ->name('update');

        // Activate or deactivate. There is no delete: businesses already filed
        // under a category keep their classification.
        Route::post('{category}/status', [BusinessCategoryController::class, 'changeStatus'])
            ->middleware('permission:'.Permission::CategoriesManage->value)
            ->whereNumber('category')
            ->name('status');
    });
});
