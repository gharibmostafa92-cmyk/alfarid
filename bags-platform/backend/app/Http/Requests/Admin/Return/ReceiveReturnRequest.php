<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Return;

use Illuminate\Foundation\Http\FormRequest;

/** استلام وفحص مرتجع: لكل بند كمية سليمة وكمية تالفة (مجموعهما ≤ المعتمد). */
class ReceiveReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_good_qty' => ['required', 'numeric', 'min:0'],
            'items.*.received_damaged_qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
