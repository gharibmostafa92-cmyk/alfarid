<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookLog;
use Throwable;

/**
 * معالجة ويب هوك بوابات الدفع:
 * تسجيل أولًا (Idempotency) ← تحقق HMAC ← تحديث حالة الدفع ←
 * عند النجاح تأكيد الطلب تلقائيًا عبر OrderService (حجز + توزيع).
 */
class PaymentWebhookService
{
    private const SUCCESS = ['paid', 'success', 'successful', 'succeeded', 'captured', 'completed', 'approved'];

    private const FAILED = ['failed', 'voided', 'declined', 'error', 'cancelled', 'canceled'];

    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected OrderService $orders,
    ) {}

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $gateway, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $provider = 'payment:'.$gateway;
        $log = $this->startLog($provider, $this->extractEventId($payload), $payload, $ip);

        $config = config("webhooks.payment.{$gateway}");
        if (! is_array($config)) {
            $log?->update(['status' => 'failed', 'error' => 'بوابة دفع غير معروفة']);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown gateway']];
        }

        if (! $this->verifier->verify($config['secret'] ?? null, $rawBody, $signature)) {
            $log?->update(['status' => 'invalid_signature', 'signature_valid' => false]);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }
        $log?->update(['signature_valid' => true]);

        if ($log !== null && $this->alreadyProcessed($provider, $log)) {
            $log->update(['status' => 'duplicate']);

            return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
        }

        $orderNumber = $this->extractOrderNumber($payload);
        $order = $orderNumber !== null ? Order::query()->where('order_number', $orderNumber)->first() : null;
        if ($order === null) {
            $log?->update(['status' => 'failed', 'error' => 'طلب غير موجود: '.($orderNumber ?? '—')]);

            return ['http' => 200, 'body' => ['received' => false, 'message' => 'order not found']];
        }

        $outcome = $this->outcome($payload);
        if ($outcome === 'ignored') {
            $log?->update(['status' => 'ignored', 'error' => 'حالة دفع غير معروفة في الحمولة']);

            return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
        }

        $note = null;
        if ($outcome === 'paid') {
            $order->update(['payment_status' => 'paid']);
            if ($order->status === OrderStatus::Pending) {
                try {
                    $this->orders->changeStatus($order, OrderStatus::Confirmed, null, "تأكيد تلقائي بعد نجاح الدفع عبر {$gateway}");
                } catch (Throwable $e) {
                    // الدفع تم فعلًا — يُسجل فشل التأكيد دون إسقاط حالة الدفع
                    $note = 'تم الدفع لكن تأكيد الطلب فشل: '.$e->getMessage();
                }
            }
        } else {
            $order->update(['payment_status' => $outcome]); // failed | refunded
        }

        $log?->update(['status' => 'processed', 'error' => $note]);

        return ['http' => 200, 'body' => ['received' => true]];
    }

    private function outcome(array $payload): string
    {
        $successFlag = data_get($payload, 'obj.success') ?? data_get($payload, 'data.success') ?? data_get($payload, 'success');
        if (is_bool($successFlag)) {
            return $successFlag ? 'paid' : 'failed';
        }

        $status = strtolower((string) ($this->firstValue($payload, ['status', 'payment_status', 'data.status', 'obj.status', 'state']) ?? ''));
        if (in_array($status, self::SUCCESS, true)) {
            return 'paid';
        }
        if ($status === 'refunded' || $status === 'refund') {
            return 'refunded';
        }
        if (in_array($status, self::FAILED, true)) {
            return 'failed';
        }

        return 'ignored';
    }

    private function extractOrderNumber(array $payload): ?string
    {
        $value = $this->firstValue($payload, [
            'order_number', 'merchant_order_id', 'data.order_number',
            'obj.order.merchant_order_id', 'obj.merchant_order_id', 'metadata.order_number',
        ]);

        return $value !== null ? (string) $value : null;
    }

    private function extractEventId(array $payload): ?string
    {
        $value = $this->firstValue($payload, ['event_id', 'id', 'transaction_id', 'obj.id', 'data.id', 'data.transaction_id']);

        return $value !== null ? (string) $value : null;
    }

    /** @param array<int, string> $keys */
    private function firstValue(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function startLog(string $provider, ?string $eventId, array $payload, ?string $ip): ?WebhookLog
    {
        return WebhookLog::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'payload' => $payload,
            'status' => 'received',
            'ip_address' => $ip,
        ]);
    }

    private function alreadyProcessed(string $provider, WebhookLog $current): bool
    {
        if ($current->event_id === null) {
            return false;
        }

        return WebhookLog::query()
            ->where('provider', $provider)
            ->where('event_id', $current->event_id)
            ->where('id', '!=', $current->id)
            ->where('status', 'processed')
            ->exists();
    }
}
