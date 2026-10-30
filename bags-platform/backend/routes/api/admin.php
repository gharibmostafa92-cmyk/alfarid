<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\BannerController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController;
use App\Http\Controllers\Api\V1\Admin\CouponController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\CustomerGroupController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\InventoryController;
use App\Http\Controllers\Api\V1\Admin\LocationController;
use App\Http\Controllers\Api\V1\Admin\OrderController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\PosShiftAdminController;
use App\Http\Controllers\Api\V1\Admin\PriceListController;
use App\Http\Controllers\Api\V1\Admin\PriceListItemController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductionReceiptController;
use App\Http\Controllers\Api\V1\Admin\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\PromotionController;
use App\Http\Controllers\Api\V1\Admin\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Admin\PurchaseRequestController;
use App\Http\Controllers\Api\V1\Admin\QuotationController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\ReturnController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\ShippingCompanyController;
use App\Http\Controllers\Api\V1\Admin\ShippingRateController;
use App\Http\Controllers\Api\V1\Admin\ShippingZoneController;
use App\Http\Controllers\Api\V1\Admin\StocktakeController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\Admin\SupplierPaymentController;
use App\Http\Controllers\Api\V1\Admin\TransferController;
use App\Http\Controllers\Api\V1\Admin\TreasuryController;
use App\Http\Controllers\Api\V1\Admin\TreasuryTransferController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\WholesalePaymentNoticeController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Middleware\AuditStaffAdministration;
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
Route::pattern('supplier', '[0-9]{1,18}');
Route::pattern('role', '[0-9]{1,18}');
Route::pattern('branch', '[0-9]{1,18}');
Route::pattern('location', '[0-9]{1,18}');
Route::pattern('user', '[0-9]{1,18}');
Route::pattern('banner', '[0-9]{1,18}');
Route::pattern('purchase_request', '[0-9]{1,18}');
Route::pattern('purchase_order', '[0-9]{1,18}');
Route::pattern('supplier_invoice', '[0-9]{1,18}');
Route::pattern('supplier_payment', '[0-9]{1,18}');

// Public admin authentication endpoint (legacy — prefer /api/v1/auth/login).
// M5: تُعطَّل بضبط LEGACY_LOGIN_ENABLED=false حتى لا تتضاعف ميزانية التخمين
// عبر limiters مستقلة ولا يظهر توكن موظف على دومين الويب.
if (config('auth.legacy_login_enabled', false)) {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:admin-login')
        ->name('admin.auth.login');
}

// Staff-only API endpoints: require a Sanctum token and an active staff account.
Route::middleware(['auth:sanctum', 'auth.staff', AuditStaffAdministration::class])
    ->name('admin.')
    ->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:reports.view')->name('dashboard');
        Route::prefix('reports')->name('reports.')->middleware(['permission:reports.view', 'throttle:30,1'])->group(function (): void {
            Route::get('sales', [ReportController::class, 'sales'])->name('sales');
            Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
            Route::get('profit', [ReportController::class, 'profit'])->name('profit');
            Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
            Route::get('shifts', [ReportController::class, 'shifts'])->name('shifts');
        });
        // M3: notifications.id is uuid — non-uuid values stay 404 instead of
        // throwing Postgres 22P02 (500 + log spam) via findOrFail.
        Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

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
        // F18: each image spawns two GD conversions in-request and the UI
        // fires two uploads in parallel — throttle the admin upload path so
        // one account cannot saturate every PHP worker.
        Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->middleware('throttle:30,1')->name('products.media.store');
        Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');

        // ================== المرحلة 3: المخزون ==================
        Route::get('production-receipts/options', [ProductionReceiptController::class, 'options'])->name('production-receipts.options');
        Route::get('production-receipts', [ProductionReceiptController::class, 'index'])->name('production-receipts.index');
        Route::post('production-receipts', [ProductionReceiptController::class, 'store'])->name('production-receipts.store');
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
        Route::post('customers/{customer}/documents', [CustomerController::class, 'storeDocument'])->name('customers.documents.store');
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
        Route::get('orders/stats', [OrderController::class, 'stats'])->name('orders.stats');
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

        Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->whereNumber(['return_item', 'attachment'])->name('return-items.attachments.show');
        Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->whereNumber('invoice')->name('supplier-invoices.attachment');
        Route::get('supplier-deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->whereNumber('delivery')->name('deliveries.invoice');
        // ================== المرحلة 12: بوابة الجملة المتقدمة ==================
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->whereNumber('quotation')->name('quotations.show');
        Route::post('quotations/{quotation}/price', [QuotationController::class, 'price'])->whereNumber('quotation')->name('quotations.price');
        Route::post('quotations/{quotation}/expire', [QuotationController::class, 'expire'])->whereNumber('quotation')->name('quotations.expire');
        Route::get('payment-notices', [WholesalePaymentNoticeController::class, 'index'])->name('payment-notices.index');
        Route::get('payment-notices/{payment_notice}', [WholesalePaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
        Route::post('payment-notices/{payment_notice}/approve', [WholesalePaymentNoticeController::class, 'approve'])->whereNumber('payment_notice')->name('payment-notices.approve');
        Route::post('payment-notices/{payment_notice}/reject', [WholesalePaymentNoticeController::class, 'reject'])->whereNumber('payment_notice')->name('payment-notices.reject');
        Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');

        // ================== المرحلة 9: المرتجعات ==================
        Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
        Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->name('returns.refund');

        // ================== المرحلة 10: المشتريات والموردين ==================
        Route::apiResource('suppliers', SupplierController::class);
        Route::get('suppliers/{supplier}/users', [SupplierController::class, 'users'])->name('suppliers.users.index');
        Route::post('suppliers/{supplier}/users', [SupplierController::class, 'storeUser'])->name('suppliers.users.store');
        Route::put('suppliers/{supplier}/users/{user}', [SupplierController::class, 'updateUser'])->name('suppliers.users.update');
        Route::delete('suppliers/{supplier}/users/{user}', [SupplierController::class, 'destroyUser'])->name('suppliers.users.destroy');

        Route::apiResource('purchase-requests', PurchaseRequestController::class)
            ->parameters(['purchase-requests' => 'purchase_request']);
        Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
        Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
        Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');

        Route::apiResource('purchase-orders', PurchaseOrderController::class)
            ->parameters(['purchase-orders' => 'purchase_order']);
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::post('purchase-orders/{purchase_order}/close-short', [PurchaseOrderController::class, 'closeShort'])->name('purchase-orders.close-short');
        Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');

        Route::apiResource('supplier-invoices', SupplierInvoiceController::class)
            ->parameters(['supplier-invoices' => 'supplier_invoice']);
        Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->name('supplier-invoices.post');
        Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('supplier-invoices.cancel');

        Route::apiResource('supplier-payments', SupplierPaymentController::class)
            ->parameters(['supplier-payments' => 'supplier_payment']);
        Route::post('supplier-payments/{supplier_payment}/allocate', [SupplierPaymentController::class, 'allocate'])->name('supplier-payments.allocate');
        Route::post('supplier-payments/{supplier_payment}/void', [SupplierPaymentController::class, 'void'])->name('supplier-payments.void');

        Route::middleware('permission:store.manage|settings.view')->group(function (): void {
            Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
            Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
        });
        Route::middleware('permission:store.manage')->group(function (): void {
            Route::put('banners/reorder', [BannerController::class, 'reorder'])->name('banners.reorder');
            Route::post('banners', [BannerController::class, 'store'])->middleware('throttle:30,1')->name('banners.store');
            Route::post('banners/{banner}', [BannerController::class, 'update'])->middleware('throttle:30,1')->name('banners.update');
            Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
            Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
        });

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');

        // ================== المرحلة 11: الخزائن، المصروفات، تحويلات الخزائن، الورديات ==================
        Route::pattern('treasury', '[0-9]{1,18}');
        Route::pattern('expense', '[0-9]{1,18}');
        Route::pattern('treasury_transfer', '[0-9]{1,18}');
        Route::pattern('shift', '[0-9]{1,18}');

        Route::apiResource('treasuries', TreasuryController::class);

        Route::apiResource('expenses', ExpenseController::class);
        Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');

        Route::apiResource('treasury-transfers', TreasuryTransferController::class)
            ->parameters(['treasury-transfers' => 'treasuryTransfer'])
            ->only(['index', 'store', 'show']);
        Route::post('treasury-transfers/{treasuryTransfer}/approve', [TreasuryTransferController::class, 'approve'])
            ->name('treasury-transfers.approve');
        Route::post('treasury-transfers/{treasuryTransfer}/reject', [TreasuryTransferController::class, 'reject'])
            ->name('treasury-transfers.reject');

        Route::get('shifts', [PosShiftAdminController::class, 'index'])
            ->name('shifts.index');
        Route::get('shifts/{shift}', [PosShiftAdminController::class, 'show'])
            ->name('shifts.show');
        Route::post('shifts/{shift}/approve', [PosShiftAdminController::class, 'approve'])
            ->name('shifts.approve');
    });
