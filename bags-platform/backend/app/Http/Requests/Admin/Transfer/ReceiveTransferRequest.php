<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ReceiveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'received_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: see ApproveTransferRequest.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
