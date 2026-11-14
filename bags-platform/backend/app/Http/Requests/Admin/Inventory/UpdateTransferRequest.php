<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use Illuminate\Validation\Rule;
use App\Models\StockTransfer;

class UpdateTransferRequest extends StoreTransferRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $routeTransfer = $this->route('transfer');
        if (! $routeTransfer instanceof StockTransfer && filter_var($routeTransfer, FILTER_VALIDATE_INT)) {
            $routeTransfer = StockTransfer::query()->find((int) $routeTransfer);
        }
        $currentFrom = $routeTransfer instanceof StockTransfer ? $routeTransfer->from_branch_id : null;
        $currentTo = $routeTransfer instanceof StockTransfer ? $routeTransfer->to_branch_id : null;
        $fromBranchId = filter_var($this->input('from_branch_id', $currentFrom), FILTER_VALIDATE_INT) ?: 0;
        $toBranchId = filter_var($this->input('to_branch_id', $currentTo), FILTER_VALIDATE_INT) ?: 0;

        return [
            'from_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::notIn($toBranchId > 0 ? [$toBranchId] : []), Rule::exists('branches', 'id')->where('is_active', true)],
            'to_branch_id' => ['sometimes', 'bail', 'integer', 'min:1', Rule::notIn($fromBranchId > 0 ? [$fromBranchId] : []), Rule::exists('branches', 'id')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required_with:items', 'bail', 'integer', 'min:1'],
            'items.*.from_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $fromBranchId),
            ],
            'items.*.to_location_id' => [
                'nullable', 'bail', 'integer', 'min:1',
                Rule::exists('locations', 'id')->where('branch_id', $toBranchId),
            ],
            'items.*.requested_qty' => ['required_with:items', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
        ];
    }
}
