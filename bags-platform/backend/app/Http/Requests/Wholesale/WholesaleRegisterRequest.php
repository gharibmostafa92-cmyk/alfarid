<?php

declare(strict_types=1);

namespace App\Http\Requests\Wholesale;

use App\Enums\CustomerDocumentType;
use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Services\CustomerPhoneVerificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class WholesaleRegisterRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        app(CustomerPhoneVerificationService::class)->requireProof($this);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers')],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone_verification_token' => ['nullable', 'string', 'size:64'],
            'company_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['required', 'string', 'max:50'],
            'commercial_register' => ['required', 'string', 'max:50'],
            'documents' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'documents.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'document_types' => ['nullable', 'array', 'list', 'size:'.count(Arr::wrap($this->file('documents', [])))],
            'document_types.*' => ['nullable', Rule::in(array_column(CustomerDocumentType::cases(), 'value'))],
        ];
    }
}
