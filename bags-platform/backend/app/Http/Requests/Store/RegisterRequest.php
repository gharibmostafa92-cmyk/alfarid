<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Services\CustomerPhoneVerificationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone_verification_token' => ['nullable', 'string', 'size:64'],
        ];
    }
}
