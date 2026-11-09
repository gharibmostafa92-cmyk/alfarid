<?php

declare(strict_types=1);

namespace App\Enums\Wholesale;

enum WholesalePaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Check = 'check';
    case Deposit = 'deposit';
}
