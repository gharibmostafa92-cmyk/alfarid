<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;

/**
 * Evaluates payment method availability for storefront checkouts.
 *
 * Enforces business eligibility rules: wallet payments require an approved account,
 * credit is restricted to vetted commercial tiers, and bank transfer requires a valid InstaPay IPA.
 */
final class StorePaymentPolicy
{
    /**
     * Resolve payment methods available to a customer or guest session.
     *
     * @param  Customer|null  $customer  Current session customer or null for guest.
     * @return array<int, string> List of eligible payment method keys.
     */
    public function allowedFor(?Customer $customer): array
    {
        $configured = array_values(array_filter((array) config('store.payment_methods', ['cod'])));
        $walletEnabled = in_array('wallet', $configured, true);
        // Wallet and credit require customer qualification and cannot be granted via generic config alone.
        $methods = array_values(array_filter($configured, fn ($method) => ! in_array($method, ['wallet', 'credit'], true)));
        // Bank transfer relies on InstaPay collection and cannot operate without an active receiving address.
        if ($this->instapayAddress() === null) {
            $methods = array_values(array_diff($methods, ['bank_transfer']));
        }

        // Suspended or unapproved accounts cannot draw on stored balances.
        if ($walletEnabled && $customer !== null && $customer->status === CustomerStatus::Approved) {
            $methods[] = 'wallet';
        }
        // Deferred credit is a trade credit facility restricted to verified wholesale accounts.
        if ($customer !== null
            && $customer->status === CustomerStatus::Approved
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)) {
            $methods[] = 'credit';
        }

        return array_values(array_unique($methods));
    }

    /**
     * Retrieve and validate the configured InstaPay Payment Address (IPA).
     *
     * @return string|null Sanitized IPA or null if missing or invalid.
     */
    public function instapayAddress(): ?string
    {
        $address = trim((string) config('store.instapay_address'));

        // Strict format guard prevents surfacing malformed payment handles to buyers.
        return preg_match('/\A[a-z0-9._-]{1,100}@instapay\z/i', $address) === 1 ? $address : null;
    }

    /**
     * Build payment transfer instructions for pending bank transfer orders.
     *
     * @param  Order  $order  Pending order awaiting payment.
     * @return array{address: string, amount: string, expires_at: string}|null Instructions payload or null if ineligible/expired.
     */
    public function instapayInstructions(Order $order): ?array
    {
        $address = $this->instapayAddress();
        if ($address === null || $order->channel !== 'store' || $order->payment_method !== 'bank_transfer'
            || $order->payment_status !== 'unpaid' || $order->status !== OrderStatus::Pending) {
            return null;
        }

        // Align instruction validity with order timeout to prevent wire transfers after stock release.
        $expiresAt = $order->created_at->copy()->addMinutes(max(1, (int) config('orders.pending_online_expire_minutes', 90)));

        return $expiresAt->isFuture() ? [
            'address' => $address,
            'amount' => (string) $order->grand_total,
            'expires_at' => $expiresAt->toIso8601String(),
        ] : null;
    }
}
