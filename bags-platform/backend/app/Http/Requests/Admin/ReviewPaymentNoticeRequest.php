<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentNoticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'treasury_id' => ['nullable', 'integer', 'exists:treasuries,id'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
