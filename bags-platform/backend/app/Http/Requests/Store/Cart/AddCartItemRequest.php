<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Cart;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
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
            // البيع بالقطعة: كميات صحيحة فقط — الكسور كانت تنتج أسطرًا
            // بقيمة صفر أو كميات تُخزَّن صفرًا فتُشحن قطعة كاملة (F-03)
            'qty' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_REQUEST_QTY],
        ];
    }
}
