<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;

/**
 * Storefront payment method eligibility policy.
 *
 * Central source of truth for payment methods available at checkout:
 * - Wallet payment requires explicit config activation and an approved customer account.
 * - Credit (deferred) payment is restricted to approved wholesale or distributor accounts.
 * - Cash-on-delivery and gateway methods follow configured storefront settings.
 */
final class StorePaymentPolicy
{
    /**
     * Resolve eligible payment methods for the given customer.
     *
     * @param  Customer|null  $customer
     * @return array<int, string>
     */
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
