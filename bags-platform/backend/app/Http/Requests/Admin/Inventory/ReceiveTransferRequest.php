<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

/**
 * Validates stock transfer receipt quantities against the current transfer version.
 */
class ReceiveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'received_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Require explicit transfer version to protect against receipt of modified line items.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
