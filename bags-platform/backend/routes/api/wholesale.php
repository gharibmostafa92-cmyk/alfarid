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
    // التسجيل ورفع المستندات لتاجر الجملة
    Route::post('register', [RegisterController::class, 'register'])->middleware('throttle:customer-registration')->name('register');

    // متابعة حالة الطلب (يتطلب توكن عميل فقط، ولا يتطلب wholesale.approved)
    Route::middleware(['auth:sanctum', 'auth.customer'])->group(function (): void {
        Route::get('application-status', [RegisterController::class, 'applicationStatus'])->name('application-status');
    });
});

// بوابة الجملة المتقدمة (المرحلتان 12 و13): الحماية الثلاثية إلزامية — لا يصل
// أي عميل قطاعي أو تاجر قيد المراجعة إلى أسعار الجملة وشرائح الكميات والسداد الآجل.
Route::middleware(['auth:sanctum', 'auth.customer', 'wholesale.approved'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // الكتالوج وتصدير قوائم الأسعار PDF / Excel
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('price-list/export', [ProductController::class, 'exportPriceList'])->name('price-list.export');

    // الطلب السريع واستيراد Excel وإعادة الطلب
    Route::post('quick-order/lookup', [QuickOrderController::class, 'lookup'])->name('quick-order.lookup');
    Route::post('quick-order/import', [QuickOrderController::class, 'import'])->name('quick-order.import');
    Route::post('quick-order/add-to-cart', [QuickOrderController::class, 'addToCart'])->name('quick-order.add-to-cart');
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::post('orders/{order}/reorder', [OrderController::class, 'reorder'])->whereNumber('order')->name('orders.reorder');

    // الفواتير، كشف الحساب، وإشعارات السداد البنكي
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{order}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('order')->name('invoices.pdf');
    Route::get('statement', [InvoiceController::class, 'statement'])->name('statement');
    Route::post('payment-notices', [InvoiceController::class, 'storePaymentNotice'])->name('payment-notices.store');
    // قراءة الإشعارات والمديونية وتنزيل الإيصالات (تكملة من 12.1)
    Route::get('payment-notices', [PaymentNoticeController::class, 'index'])->name('payment-notices.index');
    Route::get('payment-notices/outstanding', [PaymentNoticeController::class, 'outstanding'])->name('payment-notices.outstanding');
    Route::get('payment-notices/{payment_notice}', [PaymentNoticeController::class, 'show'])->whereNumber('payment_notice')->name('payment-notices.show');
    Route::get('payment-notices/{notice}/receipt', [AttachmentController::class, 'paymentNotice'])->whereNumber('notice')->name('payment-notices.receipt');

    // عروض الأسعار (submit خطوة التاجر قبل تسعير الإدارة — من 12.1)
    Route::apiResource('quotations', QuotationController::class)->parameters(['quotations' => 'quotation']);
    Route::post('quotations/{quotation}/submit', [QuotationController::class, 'submit'])->whereNumber('quotation')->name('quotations.submit');
    Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept'])->whereNumber('quotation')->name('quotations.accept');
    Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->whereNumber('quotation')->name('quotations.reject');

    // الإشعارات (المرحلة 13)
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});
