<?php

declare(strict_types=1);

namespace App\Enums;

enum TreasuryTransactionType: string
{
    case OpeningBalance = 'opening_balance';
    case PosSale = 'pos_sale';
    case PosReturn = 'pos_return';
    case Expense = 'expense';
    case ExpenseReversal = 'expense_reversal';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case SupplierPayment = 'supplier_payment';
    case CustomerReceipt = 'customer_receipt';
    case CashShortage = 'cash_shortage';
    case CashOverage = 'cash_overage';
}
