<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states of wholesale customer payment notices submitted against invoices or credit.
 */
enum WholesalePaymentNoticeStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
}
