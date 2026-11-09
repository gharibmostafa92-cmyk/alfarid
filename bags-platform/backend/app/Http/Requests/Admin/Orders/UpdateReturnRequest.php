<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Orders;

use App\Enums\Orders\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Administrative updates to a return request (notes, refund method, or rejection/cancellation).
 */
class UpdateReturnRequest extends FormRequest
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
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'status' => ['nullable', Rule::in(['rejected', 'cancelled'])],
        ];
    }
}
