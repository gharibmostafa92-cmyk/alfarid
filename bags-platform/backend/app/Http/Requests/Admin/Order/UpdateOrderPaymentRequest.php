<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates manual order payment status update requests by administrators.
 */
class UpdateOrderPaymentRequest extends FormRequest
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
            'payment_status' => ['required', Rule::in(['unpaid', 'paid', 'failed', 'refunded'])],
            'notes' => ['required', 'string', 'max:500'],
            'refund_reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
