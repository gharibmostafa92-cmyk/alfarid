<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * طلبات مراحل التحويل (اعتماد/شحن/استلام): كميات اختيارية لكل عنصر،
 * وغياب عنصر يعني استخدام الكمية الافتراضية للمرحلة السابقة.
 */
class TransferItemsQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** حقل الكمية الخاص بالمرحلة الحالية — بيتحدد في الكلاس الفرعي. */
    protected string $qtyField = 'approved_qty';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $transferId = $this->route('transfer')?->id;

        return [
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
     * خريطة [معرّف العنصر => الكمية] جاهزة للخدمة.
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
}
