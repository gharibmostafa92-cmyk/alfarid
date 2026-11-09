<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PosShiftStatus: string
{
    case Open                  = 'open';
    case ClosedPendingApproval = 'closed_pending_approval';
    case Approved              = 'approved';
}
