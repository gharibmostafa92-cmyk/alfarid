<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Transfer stage item quantity requests (approval, shipment, receipt).
 * Items are optional; omitted items default to previous stage quantities.
 */
class TransferItemsQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Quantity field name for the active transfer stage (specialized in child classes). */
    protected string $qtyField = 'approved_qty';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $transferId = $this->route('transfer')?->id;

        return [
            'expected_version' => ['nullable', 'integer', 'min:1'],
            'expected_updated_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.id' => [
                'required', 'bail', 'integer', 'min:1', 'distinct',
                Rule::exists('stock_transfer_items', 'id')->where('stock_transfer_id', $transferId),
            ],
            "items.*.{$this->qtyField}" => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
        ];
    }

    /**
     * Map of [item_id => quantity] formatted for service execution.
     *
     * @return array<int, float>
     */
    public function quantitiesByItemId(): array
    {
        $map = [];

        foreach ($this->validated('items', []) ?? [] as $item) {
            $map[(int) $item['id']] = (float) $item[$this->qtyField];
        }

        return $map;
    }

    public function expectedVersion(): ?int
    {
        return $this->filled('expected_version') ? (int) $this->validated('expected_version') : null;
    }

    public function expectedUpdatedAt(): ?string
    {
        return $this->validated('expected_updated_at');
    }
}
