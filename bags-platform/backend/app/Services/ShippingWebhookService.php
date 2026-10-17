<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\WebhookLog;
use Throwable;

/**
 * معالجة ويب هوك شركات الشحن:
 * تسجيل أولًا (Idempotency) ← تحقق HMAC بسر الشركة ← تحديث البوليصة ←
 * عند delivered تحويل الطلب إلى DELIVERED (وإثبات دفع COD).
 */
class ShippingWebhookService
{
    /** مرادفات حالات شركات الشحن الشائعة → حالات البوليصة الداخلية. */
    private const STATUS_MAP = [
        'created' => ShipmentStatus::Created,
        'picked_up' => ShipmentStatus::PickedUp,
        'picked' => ShipmentStatus::PickedUp,
        'collected' => ShipmentStatus::PickedUp,
        'in_transit' => ShipmentStatus::InTransit,
        'transit' => ShipmentStatus::InTransit,
        'out_for_delivery' => ShipmentStatus::OutForDelivery,
        'out' => ShipmentStatus::OutForDelivery,
        'delivered' => ShipmentStatus::Delivered,
        'failed' => ShipmentStatus::Failed,
        'failure' => ShipmentStatus::Failed,
        'delivery_failed' => ShipmentStatus::Failed,
        'returned' => ShipmentStatus::Returned,
        'return' => ShipmentStatus::Returned,
        'rto' => ShipmentStatus::Returned,
    ];

    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected OrderService $orders,
    ) {}

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $companyCode, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $provider = 'shipping:'.$companyCode;
        $log = $this->startLog($provider, $this->extractEventId($payload), $payload, $ip);

        $company = ShippingCompany::query()->where('code', $companyCode)->first();
        if ($company === null) {
            $log?->update(['status' => 'failed', 'error' => 'شركة شحن غير معروفة']);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown company']];
        }

        $credentials = $company->api_credentials ?? [];
        $secret = $credentials['webhook_secret'] ?? $credentials['api_key'] ?? config('webhooks.shipping.default_secret');
        if (! $this->verifier->verify(is_string($secret) ? $secret : null, $rawBody, $signature)) {
            $log?->update(['status' => 'invalid_signature', 'signature_valid' => false]);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }
        $log?->update(['signature_valid' => true]);

        if ($log !== null && $this->alreadyProcessed($provider, $log)) {
            $log->update(['status' => 'duplicate']);

            return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
        }

        $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number', 'data.tracking_number']);
        $shipment = $awb !== null
            ? Shipment::query()->where('awb_number', (string) $awb)->where('shipping_company_id', $company->id)->first()
            : null;
        if ($shipment === null) {
            $log?->update(['status' => 'failed', 'error' => 'بوليصة غير موجودة: '.($awb !== null ? (string) $awb : '—')]);

            return ['http' => 200, 'body' => ['received' => false, 'message' => 'shipment not found']];
        }

        $status = $this->mapStatus($payload);
        if ($status === null) {
            $log?->update(['status' => 'ignored', 'error' => 'حالة شحن غير معروفة في الحمولة']);

            return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
        }

        $shipment->update(['status' => $status]);

        $note = null;
        if ($status === ShipmentStatus::Delivered) {
            $order = $shipment->order;
            if ($order !== null) {
                if ($order->payment_method === 'cod' && $order->payment_status !== 'paid') {
                    $order->update(['payment_status' => 'paid']);
                }
                if ($order->status === OrderStatus::Shipped) {
                    try {
                        $this->orders->changeStatus($order, OrderStatus::Delivered, null, 'تم التسليم — إشعار شركة الشحن');
                    } catch (Throwable $e) {
                        $note = 'تم تسليم البوليصة لكن تحويل الطلب فشل: '.$e->getMessage();
                    }
                }
            }
        }

        $log?->update(['status' => 'processed', 'error' => $note]);

        return ['http' => 200, 'body' => ['received' => true]];
    }

    private function mapStatus(array $payload): ?ShipmentStatus
    {
        $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
        if ($raw === null) {
            return null;
        }
        $key = str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));

        return self::STATUS_MAP[$key] ?? null;
    }

    private function extractEventId(array $payload): ?string
    {
        $value = $this->firstValue($payload, ['event_id', 'id', 'data.id', 'data.event_id']);

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
