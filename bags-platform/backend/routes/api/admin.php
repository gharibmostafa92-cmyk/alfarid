<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PriceListController;
use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\PromotionController;
use App\Http\Controllers\Api\V1\Admin\ReturnController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\ShippingCompanyController;
use App\Http\Controllers\Api\V1\Admin\ShippingRateController;
use App\Http\Controllers\Api\V1\Admin\ShippingZoneController;
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
Route::pattern('customer', '[0-9]{1,18}');
Route::pattern('document', '[0-9]{1,18}');
Route::pattern('price_list', '[0-9]{1,18}');
Route::pattern('item', '[0-9]{1,18}');
Route::pattern('order', '[0-9]{1,18}');
Route::pattern('coupon', '[0-9]{1,18}');
Route::pattern('promotion', '[0-9]{1,18}');
Route::pattern('return_request', '[0-9]{1,18}');
Route::pattern('shipping_company', '[0-9]{1,18}');
Route::pattern('shipping_zone', '[0-9]{1,18}');
Route::pattern('shipping_rate', '[0-9]{1,18}');

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

        // ================== المرحلة 3: المخزون ==================
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
            Route::post('/adjustments', [InventoryController::class, 'adjustments'])->name('adjustments');
        });

        // التحويلات المخزنية
        Route::apiResource('transfers', TransferController::class);
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

        // الجرد المخزني
        Route::apiResource('stocktakes', StocktakeController::class);
        Route::patch('stocktakes/{stocktake}/items', [StocktakeController::class, 'updateItems'])->name('stocktakes.items.update');
        Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->name('stocktakes.approve');

        // ================== المرحلة 4: الأسعار ==================
        Route::apiResource('customer-groups', CustomerGroupController::class);

        Route::apiResource('price-lists', PriceListController::class);
        Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->name('price-lists.import');
        Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->name('price-lists.export');

        Route::scopeBindings()->group(function () {
            Route::apiResource('price-lists.items', PriceListItemController::class);
        });

        // ================== المرحلة 5: العملاء ==================
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
        Route::post('customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
        Route::post('customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
        Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::scopeBindings()->group(function (): void {
            Route::patch('customers/{customer}/documents/{document}', [CustomerController::class, 'reviewDocument'])
                ->name('customers.documents.update');
            Route::get('customers/{customer}/documents/{document}/download', [CustomerController::class, 'downloadDocument'])
                ->name('customers.documents.download');
        });

        // ================== المرحلة 7: الطلبات ==================
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
        Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->name('orders.update-payment');
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->name('orders.allocate');
        // M5: فك التوزيع — كانت رسالة allocate توجّه لعملية غير موجودة
        Route::post('orders/{order}/deallocate', [OrderController::class, 'deallocate'])->name('orders.deallocate');
        Route::post('orders/{order}/shipment', [OrderController::class, 'createShipment'])->name('orders.shipment');

        // ================== المرحلة 8: الشحن ==================
        Route::apiResource('shipping-companies', ShippingCompanyController::class);
        Route::apiResource('shipping-zones', ShippingZoneController::class);
        Route::apiResource('shipping-rates', ShippingRateController::class);

        // ================== المرحلة 8: العروض والكوبونات ==================
        Route::apiResource('promotions', PromotionController::class);
        Route::apiResource('coupons', CouponController::class);

        // ================== المرحلة 9: المرتجعات ==================
        Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
        Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->name('returns.refund');

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');
    });
