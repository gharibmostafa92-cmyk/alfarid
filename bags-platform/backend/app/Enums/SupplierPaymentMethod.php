<?php

declare(strict_types=1);

namespace App\Enums;

/** طرق سداد مستحقات الموردين. */
enum SupplierPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
}
