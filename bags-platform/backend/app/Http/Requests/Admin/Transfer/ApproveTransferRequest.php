<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

class ApproveTransferRequest extends TransferItemsQtyRequest
{
    protected string $qtyField = 'approved_qty';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // M3: approving unseen edits is a real hazard — the approver must
        // name the version they reviewed.
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
    }
}
