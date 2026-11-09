<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Orders;

use App\Enums\Orders\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Complete financial refund for inspected return items: refund method and external reference.
 */
class RefundReturnRequest extends FormRequest
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
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'refund_reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
