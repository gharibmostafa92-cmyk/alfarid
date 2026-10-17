<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
