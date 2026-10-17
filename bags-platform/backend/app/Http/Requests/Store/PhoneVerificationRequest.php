<?php

declare(strict_types=1);

namespace App\Http\Requests\Store;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use Illuminate\Foundation\Http\FormRequest;

class PhoneVerificationRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'code' => [$this->routeIs('store.auth.phone-verification.verify') ? 'required' : 'sometimes', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }
}
