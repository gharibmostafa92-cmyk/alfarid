<?php

declare(strict_types=1);

namespace App\Services\CustomerEmail;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

class CustomerEmailOutbox
{
    public function welcome(Customer $customer): void
    {
        if (! $this->captureEnabled()) {
            return;
        }

        $recipient = is_string($customer->email) ? trim($customer->email) : null;
        if (! is_string($recipient) || ! $this->isSingleAddress($recipient)) {
            $this->recordSkipped('welcome', 'invalid_recipient');

            return;
        }

        $isCommercial = $customer->isCommercialTier();
        $text = $isCommercial
            ? 'تم استلام طلب إنشاء حساب الجملة وهو قيد المراجعة.'
            : 'تم إنشاء حسابك بنجاح.';

        $this->insert(
            'welcome/'.$customer->getKey(),
            [$recipient],
            $isCommercial ? 'تم استلام طلب إنشاء حساب الجملة' : 'مرحبًا بك في الفريد',
            $text,
        );
    }

    public function orderReceived(Order $order): void
    {
        if (! $this->captureEnabled()) {
            return;
        }

        $recipient = is_string($order->guest_email) ? trim($order->guest_email) : null;
        $orderNumber = $order->order_number;
        $grandTotal = $order->grand_total;

        if (! is_string($recipient) || ! $this->isSingleAddress($recipient)) {
            $this->recordSkipped('order_received', 'invalid_recipient');

            return;
        }
        if (! is_string($orderNumber) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $orderNumber) !== 1) {
            $this->recordSkipped('order_received', 'invalid_order_number');

            return;
        }
        if (! is_string($grandTotal) || preg_match('/\A\d{1,13}\.\d{2}\z/', $grandTotal) !== 1) {
            $this->recordSkipped('order_received', 'invalid_total');

            return;
        }

        $this->insert(
            'order-received/'.$order->getKey(),
            [$recipient],
            'تم استلام طلبك رقم '.$orderNumber,
            'تم استلام طلبك رقم '.$orderNumber.'. إجمالي الطلب '.$grandTotal.' جنيه مصري.',
        );
    }

    /**
     * @param  list<string>  $recipients
     *
     * @throws JsonException
     */
    private function insert(string $event, array $recipients, string $subject, string $text): void
    {
        $environment = strtolower((string) config('services.resend.environment', config('app.env', 'production')));
        $environment = preg_replace('/[^a-z0-9_-]/', '-', $environment) ?: 'production';
        $eventKey = 'customer-email:'.substr($environment, 0, 40).':'.$event;
        $from = config('services.resend.from');
        $payload = json_encode([
            'from' => is_string($from) ? $from : '',
            'to' => $recipients,
            'subject' => $subject,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $now = now();

        DB::statement(
            'INSERT INTO customer_email_outbox (event_key, payload, status, attempts, available_at, created_at, updated_at) VALUES (?, ?, ?, 0, ?, ?, ?) ON CONFLICT (event_key) DO NOTHING',
            [$eventKey, Crypt::encryptString($payload), 'pending', $now, $now, $now],
        );
    }

    private function isSingleAddress(string $email): bool
    {
        return strlen($email) <= 255
            && preg_match('/[\x00-\x20\x7F,;]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function captureEnabled(): bool
    {
        return config('services.resend.capture_enabled') === true;
    }

    private function recordSkipped(string $event, string $reason): void
    {
        Log::notice('Customer email intent skipped', ['event' => $event, 'reason' => $reason]);
    }
}
