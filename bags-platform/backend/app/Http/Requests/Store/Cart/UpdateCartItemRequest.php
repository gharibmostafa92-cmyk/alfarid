<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Cart;

use App\Services\Orders\CartService;
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
            // Item ID is resolved from route parameter {item}; qty = 0 indicates item removal.
            'qty' => ['required', 'integer', 'min:0', 'max:'.CartService::MAX_REQUEST_QTY],
        ];
    }
}
