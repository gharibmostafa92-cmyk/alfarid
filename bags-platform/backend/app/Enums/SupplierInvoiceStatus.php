<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * حالات فاتورة المورد: draft لا تؤثر في رصيده؛ posted تُثبت الاستحقاق
 * (تزيد current_balance)؛ ثم partially_paid/paid عبر الدفعات؛ cancelled ملغاة.
 */
enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
