<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Physical inspection of return items: good and damaged quantities (total <= approved).
 */
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
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'integer'],
            'items.*.received_good_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
            'items.*.received_damaged_qty' => ['required', 'integer', 'min:0', 'max:99999999'],
        ];
    }
}
