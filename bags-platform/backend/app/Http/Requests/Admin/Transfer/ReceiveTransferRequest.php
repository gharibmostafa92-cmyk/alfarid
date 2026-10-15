<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ReceiveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'received_qty';
}
