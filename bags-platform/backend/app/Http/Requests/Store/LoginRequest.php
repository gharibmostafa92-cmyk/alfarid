<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
            // Unified identifier: accepts either email address or phone number.
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }
}
