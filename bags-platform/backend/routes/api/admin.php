<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Public admin authentication endpoint.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:admin-login')
    ->name('admin.auth.login');

// Staff-only API endpoints: require a Sanctum token and an active staff account.
Route::middleware(['auth:sanctum', 'auth.staff'])
    ->name('admin.')
    ->group(function (): void {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::apiResource('branches', BranchController::class)
            ->only(['index', 'show'])
            ->middleware('permission:branches.view|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['store'])
            ->middleware('permission:branches.create|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['update'])
            ->middleware('permission:branches.update|branches.manage');
        Route::apiResource('branches', BranchController::class)
            ->only(['destroy'])
            ->middleware('permission:branches.delete|branches.manage');

        Route::scopeBindings()->group(function (): void {
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['index', 'show'])
                ->middleware('permission:locations.view|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['store'])
                ->middleware('permission:locations.create|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['update'])
                ->middleware('permission:locations.update|locations.manage');
            Route::apiResource('branches.locations', LocationController::class)
                ->only(['destroy'])
                ->middleware('permission:locations.delete|locations.manage');
        });

        Route::apiResource('users', UserController::class)
            ->only(['index', 'show'])
            ->middleware('permission:users.view|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['store'])
            ->middleware('permission:users.create|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['update'])
            ->middleware('permission:users.update|users.manage');
        Route::apiResource('users', UserController::class)
            ->only(['destroy'])
            ->middleware('permission:users.delete|users.manage');

        Route::apiResource('roles', RoleController::class)
            ->only(['index', 'show'])
            ->middleware('permission:roles.view|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['store'])
            ->middleware('permission:roles.create|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['update'])
            ->middleware('permission:roles.update|roles.manage');
        Route::apiResource('roles', RoleController::class)
            ->only(['destroy'])
            ->middleware('permission:roles.delete|roles.manage');

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:permissions.view|roles.manage')
            ->name('permissions.index');

        // Categories: authorization is handled in CategoryController via can:categories.* middleware.
        Route::post('categories/reorder', [CategoryController::class, 'reorder'])
            ->name('categories.reorder');
        Route::apiResource('categories', CategoryController::class);

        // Brands & Products: authorization is handled in their controllers via can:brands.* / can:products.* middleware.
        Route::apiResource('brands', BrandController::class);
        Route::apiResource('products', ProductController::class);

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');
    });
