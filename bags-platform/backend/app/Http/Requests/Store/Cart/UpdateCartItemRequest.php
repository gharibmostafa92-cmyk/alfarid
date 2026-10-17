<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Cart;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            // صفر يعني إزالة البند من السلة
            'qty' => ['required', 'numeric', 'min:0'],
        ];
    }
}
