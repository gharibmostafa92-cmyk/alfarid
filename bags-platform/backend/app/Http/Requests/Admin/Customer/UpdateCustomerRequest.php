<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Customer;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\PasswordByteLimit;
use App\Rules\UniqueCustomerEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates updates to customer profiles.
 *
 * Restricts modifications to sensitive financial, wholesale, or contact fields to staff with the 'customers.approve' permission.
 * Lifecycle status and account type transitions are prohibited and must use dedicated state-machine workflows.
 */
class UpdateCustomerRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        return ! $this->hasAny(['credit_limit', 'customer_group_id', 'password', 'email', 'phone', 'company_name', 'tax_number', 'commercial_register', 'payment_terms_days', 'whatsapp_phone', 'whatsapp_opt_in', 'preferred_contact_hours']) || $this->user()->can('customers.approve');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', new EmailNotUsedByOtherAccountType('customers'), new UniqueCustomerEmail($customerId)],
            'phone' => ['sometimes', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')->ignore($customerId)],
            'password' => ['sometimes', 'string', new PasswordByteLimit, 'min:12', 'max:72'],
            'type' => ['missing'],
            'status' => ['missing'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'commercial_register' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customer_groups', 'id')->where('is_active', true)],
            'credit_limit' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'preferred_contact_hours' => ['sometimes', 'nullable', 'array'],
            'preferred_contact_hours.from' => ['required_with:preferred_contact_hours', 'date_format:H:i'],
            'preferred_contact_hours.to' => ['required_with:preferred_contact_hours', 'date_format:H:i'],
        ];
    }
}
