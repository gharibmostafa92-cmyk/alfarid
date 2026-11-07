<?php

declare(strict_types=1);

namespace App\Http\Requests\Supplier\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Supplier modification request for purchase order terms awaiting procurement review.
 */
class RequestChangeRequest extends FormRequest
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
            'notes' => ['required', 'string', 'max:2000'],
        ];
    }
}
