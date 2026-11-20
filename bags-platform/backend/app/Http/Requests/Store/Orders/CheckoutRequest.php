<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Orders;

use App\Http\Requests\Concerns\NormalizesCustomerInput;
use App\Models\Customer;
use App\Services\Pos\StorePaymentPolicy;
use App\Support\StorefrontLegalPolicyVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    use NormalizesCustomerInput;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:255'],
            // Normalized above to digits/leading + so idempotency scoping and tracking stay consistent.
            'recipient_phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9]{7,15}$/'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'shipping_address_line' => ['required', 'string', 'max:500'],
            'shipping_notes' => ['nullable', 'string', 'max:500'],
            // Compare expected_total against live checkout calculation to prevent silent price changes.
            'expected_total' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            // Constrain payment methods to active configured gateways via StorePaymentPolicy.
            'payment_method' => ['required', Rule::in($this->allowedPaymentMethods())],
            'legal_policy_version' => ['sometimes', 'nullable', 'string', Rule::in([StorefrontLegalPolicyVersion::CURRENT])],
        ];
    }

    /** @return array<int, string> */
    private function allowedPaymentMethods(): array
    {
        // Single source of truth for payment methods shared with shipping options preview.
        return app(StorePaymentPolicy::class)->allowedFor(Customer::resolveShopper(auth('sanctum')->user()));
    }
}
