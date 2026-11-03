<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ShipTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'shipped_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: see ApproveTransferRequest.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
