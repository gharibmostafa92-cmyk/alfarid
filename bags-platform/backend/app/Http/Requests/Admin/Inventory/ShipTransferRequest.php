<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

/**
 * Validates stock transfer shipment quantities against the current transfer version.
 */
class ShipTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'shipped_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Enforce version match to prevent shipping quantities against outdated transfer states.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
