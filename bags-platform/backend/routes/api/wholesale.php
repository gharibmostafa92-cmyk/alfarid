<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\Shared\NotificationController;
use App\Http\Controllers\Api\V1\Wholesale\DashboardController;
use App\Http\Controllers\Api\V1\Wholesale\InvoiceController;
use App\Http\Controllers\Api\V1\Wholesale\OrderController;
use App\Http\Controllers\Api\V1\Wholesale\PaymentNoticeController;
use App\Http\Controllers\Api\V1\Wholesale\ProductController;
use App\Http\Controllers\Api\V1\Wholesale\QuickOrderController;
use App\Http\Controllers\Api\V1\Wholesale\QuotationController;
use App\Http\Controllers\Api\V1\Wholesale\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    // Merchant onboarding registration and document uploads
    Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:customer-registration')->name('register');

    // Onboarding status inspection (requires authenticated customer token; does not require wholesale approval yet)
    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
        // Re-upload replacement for rejected onboarding documents
        Route::post('documents', [RegisterController::class, 'uploadDocument'])
            ->middleware('throttle:customer-registration')->name('documents.store');
    });
});

// Advanced wholesale portal: protected by wholesale.approved middleware to restrict trade pricing and credit terms
Route::middleware(['auth:sanctum', 'auth.customer', 'wholesale.approved'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Catalog browsing and price list exports (PDF / Excel)
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('price-list/export', [ProductController::class, 'exportPriceList'])->middleware('throttle:wholesale-heavy')->name('price-list.export');

    // Quick order lookup, spreadsheet import, and reordering
    Route::post('quick-order/lookup', [QuickOrderController::class, 'lookup'])->name('quick-order.lookup');
    Route::post('quick-order/import', [QuickOrderController::class, 'import'])->middleware('throttle:wholesale-heavy')->name('quick-order.import');
    Route::post('quick-order/add-to-cart', [QuickOrderController::class, 'addToCart'])->name('quick-order.add-to-cart');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->where('order', '[0-9]{1,18}')->name('orders.show');
    Route::post('orders/{order}/reorder', [OrderController::class, 'reorder'])->where('order', '[0-9]{1,18}')->name('orders.reorder');

    // Invoices, account statements, and payment proof submissions
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{order}/pdf', [InvoiceController::class, 'pdf'])->where('order', '[0-9]{1,18}')->middleware('throttle:wholesale-heavy')->name('invoices.pdf');
    Route::get('statement', [InvoiceController::class, 'statement'])->name('statement');
    Route::post('payment-notices', [InvoiceController::class, 'storePaymentNotice'])->middleware('throttle:wholesale-heavy')->name('payment-notices.store');
    // Payment notice ledger status and receipt downloads
    Route::get('payment-notices', [PaymentNoticeController::class, 'index'])->name('payment-notices.index');
    Route::get('payment-notices/outstanding', [PaymentNoticeController::class, 'outstanding'])->name('payment-notices.outstanding');
    Route::get('payment-notices/{payment_notice}', [PaymentNoticeController::class, 'show'])->where('payment_notice', '[0-9]{1,18}')->name('payment-notices.show');
    Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->where('notice', '[0-9]{1,18}')->name('payment-notices.receipt');

    // Price quotations lifecycle
    Route::apiResource('quotations', QuotationController::class)->parameters(['quotations' => 'quotation'])->where(['quotation' => '[0-9]{1,18}']);
    Route::post('quotations/{quotation}/submit', [QuotationController::class, 'submit'])->where('quotation', '[0-9]{1,18}')->name('quotations.submit');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->where('quotation', '[0-9]{1,18}')->name('quotations.accept');
    Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->where('quotation', '[0-9]{1,18}')->name('quotations.reject');

    // Merchant notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id')->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});
