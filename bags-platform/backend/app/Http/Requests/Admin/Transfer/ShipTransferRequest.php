<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ShipTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'shipped_qty';
}
