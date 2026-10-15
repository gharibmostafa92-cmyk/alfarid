<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PriceListController;
use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\StocktakeController;
use App\Http\Controllers\Api\V1\Admin\TransferController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use Illuminate\Support\Facades\Route;

// معرّفات الكتالوج رقمية بس: أي قيمة غير رقمية تبقى 404 بدل ما Postgres يرمي خطأ نوع ويرجع 500
Route::pattern('category', '[0-9]{1,18}');
Route::pattern('brand', '[0-9]{1,18}');
Route::pattern('product', '[0-9]{1,18}');
Route::pattern('variant', '[0-9]{1,18}');
Route::pattern('media', '[0-9]{1,18}');
Route::pattern('transfer', '[0-9]{1,18}');
Route::pattern('stocktake', '[0-9]{1,18}');
Route::pattern('customer_group', '[0-9]{1,18}');
Route::pattern('price_list', '[0-9]{1,18}');
Route::pattern('item', '[0-9]{1,18}');

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

        // Catalog: authorization is handled in the controllers via can:* middleware.
        // مهم: reorder قبل apiResource عشان ميتقريش كأنه {category}
        Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::apiResource('categories', CategoryController::class);

        Route::apiResource('brands', BrandController::class);

        Route::apiResource('products', ProductController::class);
        Route::apiResource('products.variants', ProductVariantController::class)->scoped();

        Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
        Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
        Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->name('products.media.store');
        Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');

        // Inventory: العرض والتسويات — التعديل على الأرصدة بيمر من InventoryService بس
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');
        Route::post('inventory/adjustments', [InventoryController::class, 'adjustments'])->name('inventory.adjustments');

        // Transfers: أفعال دورة الحياة قبل apiResource
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
        Route::apiResource('transfers', TransferController::class);

        // Stocktakes: الاعتماد قبل apiResource
        Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->name('stocktakes.approve');
        Route::apiResource('stocktakes', StocktakeController::class);

        // Pricing: مجموعات العملاء وقوائم الأسعار وعناصرها واستيراد/تصدير CSV
        Route::apiResource('customer-groups', CustomerGroupController::class);
        Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->name('price-lists.import');
        Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->name('price-lists.export');
        Route::apiResource('price-lists', PriceListController::class);
        Route::apiResource('price-lists.items', PriceListItemController::class)->scoped();

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');
    });
