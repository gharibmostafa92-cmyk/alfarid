<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Cart;

use App\Services\Orders\CartService;
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
            // Retail unit sales enforce integer quantities to prevent fractional rounding errors or zero-quantity line persistence.
            'qty' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_REQUEST_QTY],
        ];
    }
}
