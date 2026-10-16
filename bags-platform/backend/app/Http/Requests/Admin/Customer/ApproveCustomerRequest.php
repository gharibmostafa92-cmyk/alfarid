<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveCustomerRequest extends FormRequest
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
            'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            // الاعتماد يبقي النوع أو يرقّيه لموزع — لا يحوّل لقطاعي
            'type' => ['nullable', Rule::in(['wholesale', 'distributor'])],
            'company_name' => ['sometimes', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'string', 'max:50'],
            'commercial_register' => ['sometimes', 'string', 'max:50'],
        ];
    }
}
