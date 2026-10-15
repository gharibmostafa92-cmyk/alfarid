<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ApproveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'approved_qty';
}
