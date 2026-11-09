<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

/**
 * Validates stock transfer approval quantities against the inspected version.
 */
class ApproveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'approved_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Enforce version locking so the approval applies strictly to the inspected revision.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
