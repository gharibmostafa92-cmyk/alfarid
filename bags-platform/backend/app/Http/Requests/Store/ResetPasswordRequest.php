<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Rules\PasswordByteLimit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone', 'prohibits:phone'],
            'phone' => ['nullable', 'string', 'regex:/^01[0125][0-9]{8}$/', 'required_without:email', 'prohibits:email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', new PasswordByteLimit, 'min:8', 'max:72', 'confirmed'],
        ];
    }

    use NormalizesCustomerInput;
}
