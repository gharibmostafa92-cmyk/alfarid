<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerGroupRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => [
                'sometimes', 'string', 'max:64', 'alpha_dash',
                Rule::unique('customer_groups', 'code')->ignore($this->route('customer_group')?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'discount_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
