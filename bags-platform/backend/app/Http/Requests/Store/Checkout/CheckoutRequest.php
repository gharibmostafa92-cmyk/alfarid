<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Checkout;

use App\Models\Customer;
use App\Services\StorePaymentPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient_name' => ['nullable', 'string', 'max:255'],
            // Enforce numeric phone digits to ensure distinct guest idempotency scoping and proper tracking.
            'recipient_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+][0-9\s().\-]{6,}$/'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'shipping_address_line' => ['required', 'string', 'max:500'],
            'shipping_notes' => ['nullable', 'string', 'max:500'],
            // Compare expected_total against live checkout calculation to prevent silent price changes.
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            // Constrain payment methods to active configured gateways via StorePaymentPolicy.
            'payment_method' => ['required', Rule::in($this->allowedPaymentMethods())],
        ];
    }

    /** @return array<int, string> */
    private function allowedPaymentMethods(): array
    {
        // Single source of truth for payment methods shared with shipping options preview.
        $user = auth('sanctum')->user();

        return app(StorePaymentPolicy::class)->allowedFor($user instanceof Customer ? $user : null);
    }
}
