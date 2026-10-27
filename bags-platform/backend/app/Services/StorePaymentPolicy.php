<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;

/**
 * سياسة طرق الدفع المسموحة في المتجر — المصدر الوحيد المشترك بين
 * CheckoutRequest (التحقق) وCheckoutController::shippingOptions (المعاينة).
 * المحفظة من الإعدادات فقط وللعملاء المعتمدين، والآجل لعملاء الجملة المعتمدين.
 */
final class StorePaymentPolicy
{
    /** @return array<int, string> */
    public function allowedFor(?Customer $customer): array
    {
        $configured = array_values(array_filter((array) config('store.payment_methods', ['cod'])));
        $walletEnabled = in_array('wallet', $configured, true);
        $methods = array_values(array_filter($configured, fn ($method) => ! in_array($method, ['wallet', 'credit'], true)));

        if ($walletEnabled && $customer !== null && $customer->status === CustomerStatus::Approved) {
            $methods[] = 'wallet';
        }
        if ($customer !== null
            && $customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            $methods[] = 'credit';
        }

        return array_values(array_unique($methods));
    }
}
