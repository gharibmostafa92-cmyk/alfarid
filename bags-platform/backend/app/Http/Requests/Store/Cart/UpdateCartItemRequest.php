<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Cart;

use App\Services\CartService;
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
            // البند يُعرّف من مسار الرابط {item} — هنا الكمية فقط، وصفر يعني الإزالة
            'qty' => ['required', 'integer', 'min:0', 'max:'.CartService::MAX_REQUEST_QTY],
        ];
    }
}
