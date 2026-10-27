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
        // المصدر الوحيد: StorePaymentPolicy (مشترك مع معاينة shipping-options).
        $user = auth('sanctum')->user();

        return app(StorePaymentPolicy::class)->allowedFor($user instanceof Customer ? $user : null);
    }
}
