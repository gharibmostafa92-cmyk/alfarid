<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Supplier invoice lifecycle:
 * draft (no ledger effect) -> posted (accrues payable in current_balance)
 * -> partially_paid / paid via supplier payments, or cancelled.
 */
enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
