<?php

declare(strict_types=1);

namespace App\Http\Requests\Wholesale;

use App\Enums\CustomerDocumentType;
use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\PasswordByteLimit;
use App\Rules\UniqueCustomerEmail;
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
        // In JSON tests UploadedFile instances live inside all('documents') rather than file('documents').
        $docs = $this->all('documents')['documents'] ?? $this->file('documents') ?? [];

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')],
            'password' => ['required', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
            'phone_verification_token' => ['nullable', 'string', 'size:64'],
            'company_name' => ['required', 'string', 'max:255'],
            'tax_number' => ['required', 'string', 'max:50'],
            'commercial_register' => ['required', 'string', 'max:50'],
            'documents' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'documents.*' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'max:5120',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof \Illuminate\Http\UploadedFile || $value->getRealPath() === false) {
                        return;
                    }
                    $head = (string) @file_get_contents($value->getRealPath(), false, null, 0, 4);
                    if (str_starts_with($head, 'MZ')) {
                        $fail('نوع الملف المرفوع غير مدعوم.');
                    }
                },
            ],
            'document_types' => ['nullable', 'array', 'list', 'size:'.count(Arr::wrap($docs))],
            'document_types.*' => ['nullable', Rule::enum(CustomerDocumentType::class)],
        ];
    }
}
