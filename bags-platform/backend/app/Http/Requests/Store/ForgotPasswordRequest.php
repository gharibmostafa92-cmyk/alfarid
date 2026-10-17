<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ForgotPasswordRequest extends FormRequest
{
    use NormalizesCustomerInput;

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
            'email' => ['nullable', 'email', 'max:255', 'prohibits:phone'],
            'phone' => ['nullable', 'string', 'regex:/^01[0125][0-9]{8}$/', 'prohibits:email'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('email') && ! $this->filled('phone')) {
                $v->errors()->add('email', 'أدخل البريد الإلكتروني أو رقم الهاتف.');
            }
        });
    }
}
