<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\PasswordByteLimit;
use App\Rules\UniqueCustomerEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        return (! $this->hasAny(['credit_limit', 'customer_group_id'])
            && ! in_array($this->input('type'), CustomerType::commercialValues(), true))
            || $this->user()->can('customers.approve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail],
            'phone' => ['required', 'string', 'regex:/\A01[0125][0-9]{8}\z/', Rule::unique('customers', 'phone')->whereNull('deleted_at')],
            // Staff-assigned passwords intentionally require 12 characters; retail self-service allows 8.
            'password' => ['required', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
            'type' => ['nullable', Rule::enum(CustomerType::class)],
            'status' => [
                'nullable',
                Rule::in([CustomerStatus::Pending->value, CustomerStatus::Approved->value]),
                Rule::when(
                    ($this->input('type') ?? CustomerType::Retail->value) === CustomerType::Retail->value,
                    [Rule::in([CustomerStatus::Approved->value])],
                ),
            ],
            'company_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'commercial_register' => ['nullable', 'string', 'max:50'],
            'customer_group_id' => ['nullable', 'integer', Rule::exists('customer_groups', 'id')->where('is_active', true)],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }
}
