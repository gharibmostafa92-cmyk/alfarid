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
            // صيغة هاتف تحمل أرقامًا (M3): مدخل بلا أرقام كان يوحّد إلى هوية
            // مشتركة 'guest:' فيفسح نطاق idempotency عامًا وتتبعًا خاطئًا
            'recipient_phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+][0-9\s().\-]{6,}$/'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'shipping_address_line' => ['required', 'string', 'max:500'],
            'shipping_notes' => ['nullable', 'string', 'max:500'],
            // الإجمالي الذي رآه العميل — يُقارن بالمحسوب لمنع تغيّر السعر الصامت (M6)
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            // طرق الدفع من الإعدادات فقط — طريقة بلا بوابة مربوطة لا تُقبل (H9)
            'payment_method' => ['required', Rule::in($this->allowedPaymentMethods())],
        ];
    }

    /** @return array<int, string> */
    private function allowedPaymentMethods(): array
    {
        $configured = array_values(array_filter((array) config('store.payment_methods', ['cod'])));
        $walletEnabled = in_array('wallet', $configured, true);
        $methods = array_values(array_filter($configured, fn ($method) => $method !== 'wallet'));

        // المحفظة من الإعدادات فقط، ولعملاء معتمدين. إيقافها يتم بحذفها من STORE_PAYMENT_METHODS.
        $user = auth('sanctum')->user();
        if ($walletEnabled && $user instanceof Customer && $user->status === CustomerStatus::Approved) {
            $methods[] = 'wallet';
        }
        if ($user instanceof Customer
            && $user->status === CustomerStatus::Approved
            && in_array($user->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            $methods[] = 'credit';
        }

        return array_values(array_unique($methods));
    }
}
