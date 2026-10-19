<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** أُنشئت تحويلة مخزون جديدة بانتظار الاعتماد. */
class TransferPendingApproval
{
    use Dispatchable, SerializesModels;

    public function __construct(public StockTransfer $transfer) {}
}
