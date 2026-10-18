<?php

declare(strict_types=1);

namespace App\Enums;

/** طرق رد مبلغ المرتجع للعميل. */
enum RefundMethod: string
{
    case OriginalPayment = 'original_payment'; // نفس وسيلة الدفع الأصلية (عبر البوابة)
    case Wallet = 'wallet';                     // رصيد محفظة العميل داخل المتجر
    case BankTransfer = 'bank_transfer';        // تحويل بنكي يدوي بمرجع
    case Cash = 'cash';                         // نقدًا من الفرع
}
