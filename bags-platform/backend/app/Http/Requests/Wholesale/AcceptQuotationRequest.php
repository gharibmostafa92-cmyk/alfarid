<?php

declare(strict_types=1);

namespace App\Http\Requests\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

class AcceptQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
