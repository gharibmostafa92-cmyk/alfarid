<?php

declare(strict_types=1);

namespace App\Enums\Orders;

/**
 * Supported payment channels for reimbursing approved customer return requests.
 */
enum RefundMethod: string
{
    case OriginalPayment = 'original_payment';
    case Wallet = 'wallet';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
}
