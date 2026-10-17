<?php

declare(strict_types=1);

namespace App\Http\Requests\Store\Checkout;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
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
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'shipping_address_line' => ['required', 'string', 'max:500'],
            'shipping_notes' => ['nullable', 'string', 'max:500'],
            // طرق الدفع من الإعدادات فقط — طريقة بلا بوابة مربوطة لا تُقبل (H9)
            'payment_method' => ['required', Rule::in($this->allowedPaymentMethods())],
        ];
    }

    /** @return array<int, string> */
    private function allowedPaymentMethods(): array
    {
        $methods = (array) config('store.payment_methods', ['cod']);

        // الدفع الآجل (credit) لعملاء الجملة/التوزيع المعتمدين فقط
        $user = auth('sanctum')->user();
        if ($user instanceof Customer
            && $user->status === CustomerStatus::Approved
            && in_array($user->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            $methods[] = 'credit';
        }

        return array_values(array_unique($methods));
    }
}
