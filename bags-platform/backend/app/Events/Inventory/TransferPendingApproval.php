<?php

declare(strict_types=1);

namespace App\Events\Inventory;

use App\Models\StockTransfer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after transaction commit when a new stock transfer requires manager approval,
 * preventing race conditions and ModelNotFoundException in async queue workers.
 */
class TransferPendingApproval implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public StockTransfer $transfer) {}
}
