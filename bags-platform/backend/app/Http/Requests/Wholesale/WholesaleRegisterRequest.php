<?php

declare(strict_types=1);

namespace App\Http\Requests\Wholesale;

use App\Enums\CustomerDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WholesaleRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')],
            'phone' => ['required', 'string', 'max:20', Rule::unique('customers', 'phone')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['required', 'string', 'max:50'],
            'commercial_register' => ['required', 'string', 'max:50'],
            'documents' => ['required', 'array', 'min:1', 'max:5'],
            'documents.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'document_types' => ['nullable', 'array', 'max:5'],
            'document_types.*' => ['nullable', Rule::in(array_column(CustomerDocumentType::cases(), 'value'))],
        ];
    }
}
