<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
        $customerId = $this->route('customer')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customerId)],
            'phone' => ['sometimes', 'string', 'max:20', Rule::unique('customers', 'phone')->ignore($customerId)],
            'password' => ['sometimes', 'string', 'min:8', 'max:255'],
            'type' => ['sometimes', Rule::in(array_column(CustomerType::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(CustomerStatus::cases(), 'value'))],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_groups', 'id')],
            'credit_limit' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
