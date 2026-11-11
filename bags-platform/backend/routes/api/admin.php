<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\AiActionController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiAdministrationController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiAttachmentController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiConversationController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiMentionController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiMessageController;
use App\Http\Controllers\Api\V1\Admin\Ai\AiWelcomeController;
use App\Http\Controllers\Api\V1\Admin\Ai\WhatsAppAdminController;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\Catalog\BannerController;
use App\Http\Controllers\Api\V1\Admin\Inventory\BranchController;
use App\Http\Controllers\Api\V1\Admin\Catalog\BrandController;
use App\Http\Controllers\Api\V1\Admin\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Admin\Pricing\CouponController;
use App\Http\Controllers\Api\V1\Admin\Customer\CustomerController;
use App\Http\Controllers\Api\V1\Admin\Customer\CustomerGroupController;
use App\Http\Controllers\Api\V1\Admin\Customer\CustomerWalletController;
use App\Http\Controllers\Api\V1\Admin\Finance\DashboardController;
use App\Http\Controllers\Api\V1\Admin\Finance\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Admin\Inventory\LocationController;
use App\Http\Controllers\Api\V1\Admin\Orders\OrderController;
use App\Http\Controllers\Api\V1\Admin\Auth\PermissionController;
use App\Http\Controllers\Api\V1\Admin\Finance\PosShiftAdminController;
use App\Http\Controllers\Api\V1\Admin\Pricing\PriceListController;
use App\Http\Controllers\Api\V1\Admin\Pricing\PriceListItemController;
use App\Http\Controllers\Api\V1\Admin\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Admin\Inventory\ProductionReceiptController;
use App\Http\Controllers\Api\V1\Admin\Catalog\ProductMediaController;
use App\Http\Controllers\Api\V1\Admin\Catalog\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\Pricing\PromotionController;
use App\Http\Controllers\Api\V1\Admin\Procurement\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Admin\Procurement\PurchaseRequestController;
use App\Http\Controllers\Api\V1\Admin\Orders\QuotationController;
use App\Http\Controllers\Api\V1\Admin\Finance\ReportController;
use App\Http\Controllers\Api\V1\Admin\Orders\ReturnController;
use App\Http\Controllers\Api\V1\Admin\Auth\RoleController;
use App\Http\Controllers\Api\V1\Admin\Auth\SettingController;
use App\Http\Controllers\Api\V1\Admin\Orders\ShippingCompanyController;
use App\Http\Controllers\Api\V1\Admin\Orders\ShippingRateController;
use App\Http\Controllers\Api\V1\Admin\Orders\ShippingZoneController;
use App\Http\Controllers\Api\V1\Admin\Inventory\StocktakeController;
use App\Http\Controllers\Api\V1\Admin\Procurement\SupplierController;
use App\Http\Controllers\Api\V1\Admin\Procurement\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\Admin\Procurement\SupplierPaymentController;
use App\Http\Controllers\Api\V1\Admin\Inventory\TransferController;
use App\Http\Controllers\Api\V1\Admin\Finance\TreasuryController;
use App\Http\Controllers\Api\V1\Admin\Finance\TreasuryTransferController;
use App\Http\Controllers\Api\V1\Admin\Auth\UserController;
use App\Http\Controllers\Api\V1\Admin\Orders\WholesalePaymentNoticeController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Middleware\AuditStaffAdministration;
use Illuminate\Support\Facades\Route;

// Enforce numeric route patterns: non-numeric segments yield 404 instead of Postgres type errors (500).
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
// Constrain AI route parameters: conversation IDs are numeric and action IDs are 26-char ULIDs,
// failing cleanly with 404 rather than triggering PostgreSQL syntax casting errors (22P02).
Route::pattern('conversation', '[0-9]{1,18}');
Route::pattern('action', '[0-9A-Za-z]{26}');

// Public admin authentication endpoint (legacy — prefer /api/v1/auth/login).
// Disabled when LEGACY_LOGIN_ENABLED=false to avoid splitting brute-force rate limits across endpoints.
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
        Route::prefix('reports')->name('reports.')->middleware(['permission:reports.view', 'throttle:admin-reports'])->group(function (): void {
            Route::get('sales', [ReportController::class, 'sales'])->name('sales');
            Route::get('inventory', [ReportController::class, 'inventory'])->name('inventory');
            Route::get('profit', [ReportController::class, 'profit'])->name('profit');
            Route::get('suppliers', [ReportController::class, 'suppliers'])->name('suppliers');
            Route::get('shifts', [ReportController::class, 'shifts'])->name('shifts');
        });
        // Constrain notification IDs to UUIDs so malformed identifiers return 404 rather than casting exceptions.
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

        // Allow self-service rotation for flagged temporary passwords without requiring users.update permission.
        Route::put('me/password', [UserController::class, 'updateOwnPassword'])->name('me.password');
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
        // Place custom categories/reorder before apiResource to prevent matching {category} parameter.
        Route::put('categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::apiResource('categories', CategoryController::class);

        Route::apiResource('brands', BrandController::class);

        Route::apiResource('products', ProductController::class);
        Route::apiResource('products.variants', ProductVariantController::class)->scoped();

        Route::put('products/{product}/media/reorder', [ProductMediaController::class, 'reorder'])->name('products.media.reorder');
        Route::get('products/{product}/media', [ProductMediaController::class, 'index'])->name('products.media.index');
        // Throttle product media uploads to prevent CPU saturation from synchronous image processing.
        Route::post('products/{product}/media', [ProductMediaController::class, 'store'])->middleware('throttle:admin-media-uploads')->name('products.media.store');
        Route::delete('products/{product}/media/{media}', [ProductMediaController::class, 'destroy'])->name('products.media.destroy');

        // Inventory management
        Route::get('production-receipts/options', [ProductionReceiptController::class, 'options'])->name('production-receipts.options');
        Route::get('production-receipts', [ProductionReceiptController::class, 'index'])->name('production-receipts.index');
        Route::post('production-receipts', [ProductionReceiptController::class, 'store'])->name('production-receipts.store');
        Route::prefix('inventory')->name('inventory.')->group(function () {
            Route::get('/', [InventoryController::class, 'index'])->name('index');
            Route::get('/movements', [InventoryController::class, 'movements'])->name('movements');
            Route::post('/adjustments', [InventoryController::class, 'adjustments'])->middleware('permission:inventory.adjust')->name('adjustments');
        });

        // Stock transfers
        Route::apiResource('transfers', TransferController::class);
        Route::post('transfers/{transfer}/approve', [TransferController::class, 'approve'])->name('transfers.approve');
        Route::post('transfers/{transfer}/ship', [TransferController::class, 'ship'])->name('transfers.ship');
        Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');
        Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');

        // Stocktake sessions
        Route::apiResource('stocktakes', StocktakeController::class);
        Route::patch('stocktakes/{stocktake}/items', [StocktakeController::class, 'updateItems'])->name('stocktakes.items.update');
        Route::post('stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve'])->middleware('permission:stocktakes.approve')->name('stocktakes.approve');

        // Pricing and customer groups
        Route::apiResource('customer-groups', CustomerGroupController::class);

        Route::apiResource('price-lists', PriceListController::class);
        Route::post('price-lists/{price_list}/import', [PriceListController::class, 'import'])->middleware('throttle:admin-heavy')->name('price-lists.import');
        Route::get('price-lists/{price_list}/export', [PriceListController::class, 'export'])->middleware('throttle:admin-heavy')->name('price-lists.export');
        Route::post('price-lists/{price_list}/apply-percentage', [PriceListController::class, 'applyPercentage'])->middleware('permission:price_lists.update')->name('price-lists.apply-percentage');

        Route::scopeBindings()->group(function () {
            Route::apiResource('price-lists.items', PriceListItemController::class);
        });

        // Customer management
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/documents', [CustomerController::class, 'storeDocument'])->middleware('throttle:admin-heavy')->name('customers.documents.store');
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');
        Route::post('customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
        Route::post('customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
        Route::post('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
        Route::post('customers/{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('customers.reactivate');
        Route::get('customers/{customer}/history', [CustomerController::class, 'history'])->name('customers.history');
        Route::get('customers/{customer}/wallet', [CustomerWalletController::class, 'show'])->middleware('permission:customers.wallet.view')->name('customers.wallet.show');
        Route::get('customers/{customer}/wallet/summary', [CustomerWalletController::class, 'summary'])->middleware('permission:customers.wallet.view')->name('customers.wallet.summary');
        Route::get('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'adjustments'])->middleware('permission:customers.wallet.adjust')->name('customers.wallet.adjustments.index');
        Route::post('customers/{customer}/wallet/adjustments', [CustomerWalletController::class, 'storeAdjustment'])->middleware('permission:customers.wallet.adjust')->name('customers.wallet.adjustments.store');
        Route::delete('wallet-adjustments/{adjustment}', [CustomerWalletController::class, 'reverseAdjustment'])->where('adjustment', '[0-9]{1,18}')->middleware('permission:customers.wallet.adjust')->name('wallet.adjustments.reverse');
        Route::scopeBindings()->group(function (): void {
            Route::patch('customers/{customer}/documents/{document}', [CustomerController::class, 'reviewDocument'])
                ->middleware('throttle:admin-heavy')
                ->name('customers.documents.update');
            Route::get('customers/{customer}/documents/{document}/download', [CustomerController::class, 'downloadDocument'])
                ->name('customers.documents.download');
        });

        // Order management
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/stats', [OrderController::class, 'stats'])->name('orders.stats');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
        Route::post('orders/{order}/payment', [OrderController::class, 'updatePayment'])->middleware('permission:orders.payment')->name('orders.update-payment');
        Route::post('orders/{order}/allocate', [OrderController::class, 'allocate'])->middleware('permission:orders.allocate')->name('orders.allocate');
        // Releases allocated branch stock for an order back into available inventory.
        Route::post('orders/{order}/deallocate', [OrderController::class, 'deallocate'])->middleware('permission:orders.allocate')->name('orders.deallocate');
        Route::post('orders/{order}/shipment', [OrderController::class, 'createShipment'])->name('orders.shipment');

        // Shipping carriers, zones, and rates
        Route::apiResource('shipping-companies', ShippingCompanyController::class);
        Route::apiResource('shipping-zones', ShippingZoneController::class);
        Route::apiResource('shipping-rates', ShippingRateController::class);

        // Promotions and coupons
        Route::apiResource('promotions', PromotionController::class);
        Route::apiResource('coupons', CouponController::class);

        Route::get('return-items/{return_item}/attachments/{attachment}', [AttachmentController::class, 'returnFile'])->where(['return_item' => '[0-9]{1,18}', 'attachment' => '[0-9]{1,18}'])->name('return-items.attachments.show');
        Route::get('supplier-invoices/{invoice}/attachment', [AttachmentController::class, 'invoice'])->where('invoice', '[0-9]{1,18}')->name('supplier-invoices.attachment');
        Route::get('supplier-deliveries/{delivery}/invoice', [AttachmentController::class, 'delivery'])->where('delivery', '[0-9]{1,18}')->name('deliveries.invoice');
        // Wholesale quotations and payment notices
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->where('quotation', '[0-9]{1,18}')->name('quotations.show');
        Route::post('quotations/{quotation}/price', [QuotationController::class, 'price'])->where('quotation', '[0-9]{1,18}')->middleware('permission:quotations.price')->name('quotations.price');
        Route::post('quotations/{quotation}/expire', [QuotationController::class, 'expire'])->where('quotation', '[0-9]{1,18}')->name('quotations.expire');
        Route::get('payment-notices', [WholesalePaymentNoticeController::class, 'index'])->name('payment-notices.index');
        Route::get('payment-notices/{payment_notice}', [WholesalePaymentNoticeController::class, 'show'])->where('payment_notice', '[0-9]{1,18}')->name('payment-notices.show');
        Route::post('payment-notices/{payment_notice}/approve', [WholesalePaymentNoticeController::class, 'approve'])->where('payment_notice', '[0-9]{1,18}')->middleware('permission:payment_notices.approve')->name('payment-notices.approve');
        Route::post('payment-notices/{payment_notice}/reject', [WholesalePaymentNoticeController::class, 'reject'])->where('payment_notice', '[0-9]{1,18}')->middleware('permission:payment_notices.reject')->name('payment-notices.reject');
        Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->where('notice', '[0-9]{1,18}')->name('payment-notices.receipt');

        // Return requests (RMA)
        Route::apiResource('returns', ReturnController::class)->parameters(['returns' => 'return_request']);
        Route::post('returns/{return_request}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('returns/{return_request}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('returns/{return_request}/refund', [ReturnController::class, 'refund'])->middleware('permission:returns.refund')->name('returns.refund');

        // Procurement, suppliers, and purchase orders
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
        Route::post('supplier-invoices/{supplier_invoice}/post', [SupplierInvoiceController::class, 'post'])->middleware('permission:supplier_invoices.post')->name('supplier-invoices.post');
        Route::post('supplier-invoices/{supplier_invoice}/cancel', [SupplierInvoiceController::class, 'cancel'])->middleware('permission:supplier_invoices.cancel')->name('supplier-invoices.cancel');

        Route::apiResource('supplier-payments', SupplierPaymentController::class)
            ->parameters(['supplier-payments' => 'supplier_payment']);
        Route::post('supplier-payments/{supplier_payment}/allocate', [SupplierPaymentController::class, 'allocate'])->middleware('permission:supplier_payments.update')->name('supplier-payments.allocate');
        Route::post('supplier-payments/{supplier_payment}/void', [SupplierPaymentController::class, 'void'])->middleware('permission:supplier_payments.delete')->name('supplier-payments.void');

        Route::middleware('permission:store.manage|settings.view')->group(function (): void {
            Route::get('banners', [BannerController::class, 'index'])->name('banners.index');
            Route::get('banners/{banner}', [BannerController::class, 'show'])->name('banners.show');
        });
        Route::middleware('permission:store.manage')->group(function (): void {
            Route::put('banners/reorder', [BannerController::class, 'reorder'])->name('banners.reorder');
            Route::post('banners', [BannerController::class, 'store'])->middleware('throttle:admin-banners')->name('banners.store');
            Route::post('banners/{banner}', [BannerController::class, 'update'])->middleware('throttle:admin-banners')->name('banners.update');
            Route::patch('banners/{banner}/toggle', [BannerController::class, 'toggle'])->name('banners.toggle');
            Route::delete('banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
        });

        Route::get('settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage')
            ->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.update|settings.manage')
            ->name('settings.update');

        // Treasuries, expenses, transfers, and shifts
        Route::pattern('treasury', '[0-9]{1,18}');
        Route::pattern('expense', '[0-9]{1,18}');
        Route::pattern('treasury_transfer', '[0-9]{1,18}');
        Route::pattern('treasuryTransfer', '[0-9]{1,18}');
        Route::pattern('identity', '[0-9]{1,18}');
        Route::pattern('shift', '[0-9]{1,18}');

        Route::apiResource('treasuries', TreasuryController::class)
            ->only(['index', 'show'])
            ->middleware('permission:treasuries.view');
        Route::apiResource('treasuries', TreasuryController::class)
            ->only(['store'])
            ->middleware('permission:treasuries.create');
        Route::apiResource('treasuries', TreasuryController::class)
            ->only(['update'])
            ->middleware('permission:treasuries.update');
        Route::apiResource('treasuries', TreasuryController::class)
            ->only(['destroy'])
            ->middleware('permission:treasuries.delete');

        Route::apiResource('expenses', ExpenseController::class)
            ->only(['index', 'show'])
            ->middleware('permission:expenses.view');
        Route::apiResource('expenses', ExpenseController::class)
            ->only(['store'])
            ->middleware('permission:expenses.create');
        Route::apiResource('expenses', ExpenseController::class)
            ->only(['update'])
            ->middleware('permission:expenses.update');
        Route::apiResource('expenses', ExpenseController::class)
            ->only(['destroy'])
            ->middleware('permission:expenses.delete');
        Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->middleware('permission:expenses.view')->name('expenses.attachment');
        Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->middleware('permission:expenses.delete')->name('expenses.void');

        Route::apiResource('treasury-transfers', TreasuryTransferController::class)
            ->parameters(['treasury-transfers' => 'treasuryTransfer'])
            ->only(['index', 'show'])
            ->middleware('permission:treasury_transfers.view');
        Route::apiResource('treasury-transfers', TreasuryTransferController::class)
            ->parameters(['treasury-transfers' => 'treasuryTransfer'])
            ->only(['store'])
            ->middleware('permission:treasury_transfers.create');
        Route::post('treasury-transfers/{treasuryTransfer}/approve', [TreasuryTransferController::class, 'approve'])
            ->middleware('permission:treasury_transfers.approve')
            ->name('treasury-transfers.approve');
        Route::post('treasury-transfers/{treasuryTransfer}/reject', [TreasuryTransferController::class, 'reject'])
            ->middleware('permission:treasury_transfers.approve')
            ->name('treasury-transfers.reject');

        Route::get('shifts', [PosShiftAdminController::class, 'index'])
            ->name('shifts.index');
        Route::get('shifts/{shift}', [PosShiftAdminController::class, 'show'])
            ->name('shifts.show');
        Route::post('shifts/{shift}/approve', [PosShiftAdminController::class, 'approve'])
            ->name('shifts.approve');

        Route::prefix('ai')->name('ai.')->middleware('permission:ai_agent.use')->group(function (): void {
            Route::get('settings', [AiAdministrationController::class, 'settings'])->middleware('permission:ai_agent.settings')->name('settings');
            Route::put('settings', [AiAdministrationController::class, 'updateSettings'])->middleware('permission:ai_agent.settings')->name('settings.update');
            Route::get('usage', [AiAdministrationController::class, 'usage'])->name('usage');
            Route::middleware('permission:ai_agent.settings')->group(function (): void {
                Route::get('whatsapp/status', [WhatsAppAdminController::class, 'status'])->name('whatsapp.status');
                Route::get('whatsapp/qr', [WhatsAppAdminController::class, 'qr'])->name('whatsapp.qr');
                Route::post('whatsapp/logout', [WhatsAppAdminController::class, 'logout'])->name('whatsapp.logout');
                Route::get('whatsapp/identities', [WhatsAppAdminController::class, 'identities'])->name('whatsapp.identities');
                Route::post('whatsapp/identities', [WhatsAppAdminController::class, 'storeIdentity'])->name('whatsapp.identities.store');
                Route::post('whatsapp/identities/{identity}/link', [WhatsAppAdminController::class, 'link'])->name('whatsapp.identities.link');
                Route::post('whatsapp/identities/{identity}/revoke', [WhatsAppAdminController::class, 'revoke'])->name('whatsapp.identities.revoke');
                Route::get('whatsapp/staff', [WhatsAppAdminController::class, 'staff'])->name('whatsapp.staff');
                Route::get('whatsapp/monitor', [WhatsAppAdminController::class, 'monitor'])->name('whatsapp.monitor');
            });
            Route::patch('conversations/{conversation}/pin', [AiConversationController::class, 'pin'])->name('conversations.pin');
            Route::post('conversations/{conversation}/stream', [AiMessageController::class, 'stream'])->middleware('throttle:ai-chat')->name('conversations.stream');
            Route::get('conversations', [AiConversationController::class, 'index'])->name('conversations.index');
            // Throttle conversation creation to rate-limit conversation record and initial title setup.
            Route::post('conversations', [AiConversationController::class, 'store'])->middleware('throttle:ai-conversations')->name('conversations.store');
            Route::get('conversations/{conversation}', [AiConversationController::class, 'show'])->name('conversations.show');
            Route::delete('conversations/{conversation}', [AiConversationController::class, 'destroy'])->name('conversations.destroy');
            Route::post('conversations/{conversation}/messages', [AiMessageController::class, 'store'])->middleware('throttle:ai-chat')->name('conversations.messages.store');
            Route::post('conversations/{conversation}/attachments', [AiAttachmentController::class, 'store'])->middleware('throttle:ai-attachments')->name('conversations.attachments.store');
            Route::post('actions/{action}/confirm', [AiActionController::class, 'confirm'])->middleware('permission:ai_agent.execute')->name('actions.confirm');
            Route::post('actions/{action}/cancel', [AiActionController::class, 'cancel'])->name('actions.cancel');
            Route::post('actions/{action}/undo', [AiActionController::class, 'undo'])->middleware('permission:ai_agent.execute')->name('actions.undo');
            // Throttle debounced autocomplete queries on staff and product mentions.
            Route::get('mentions', [AiMentionController::class, 'search'])->middleware('throttle:ai-mentions')->name('mentions');
            Route::get('welcome', [AiWelcomeController::class, 'show'])->name('welcome');
        });
    });
