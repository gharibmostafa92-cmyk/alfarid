<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Disbursement settlement methods for commercial vendor and supplier invoices.
 */
enum SupplierPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
}
