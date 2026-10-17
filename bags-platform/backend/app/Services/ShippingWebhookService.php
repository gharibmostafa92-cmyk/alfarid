<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * معالجة ويب هوك شركات الشحن:
 * تسجيل أولًا ← تحقق HMAC بسر الشركة ← معالجة ذرّية بقفل استشاري (H4) ←
 * تحديث البوليصة مع احترام ترتيب الحالات وعدم التراجع من حالة نهائية (H6) ←
 * عند delivered تحويل الطلب إلى DELIVERED وإثبات دفع COD.
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

    /** رتبة التقدم في سلسلة التسليم — التراجع في الرتبة يعني حدثًا متأخرًا. */
    private const RANK = [
        'created' => 0,
        'picked_up' => 1,
        'in_transit' => 2,
        'out_for_delivery' => 3,
        'delivered' => 4,
        'returned' => 4,
    ];

    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected OrderService $orders,
    ) {}

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $companyCode, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $provider = 'shipping:'.$companyCode;
        $eventId = $this->effectiveEventId($payload, $rawBody);
        $log = $this->startLog($provider, $eventId, $payload, $ip);

        $company = ShippingCompany::query()->where('code', $companyCode)->first();
        if ($company === null) {
            $log->update(['status' => 'failed', 'error' => 'شركة شحن غير معروفة']);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown company']];
        }

        $credentials = $company->api_credentials ?? [];
        $secret = $credentials['webhook_secret'] ?? $credentials['api_key'] ?? config('webhooks.shipping.default_secret');
        if (! $this->verifier->verify(is_string($secret) ? $secret : null, $rawBody, $signature)) {
            $log->update(['status' => 'invalid_signature', 'signature_valid' => false]);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }
        $log->update(['signature_valid' => true]);

        return DB::transaction(function () use ($provider, $payload, $log, $company): array {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$provider.':'.$log->event_id]);

            if ($this->alreadyProcessed($provider, $log)) {
                $log->update(['status' => 'duplicate']);

                return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
            }

            $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number', 'data.tracking_number']);
            $shipment = $awb !== null
                ? Shipment::query()->where('awb_number', (string) $awb)->where('shipping_company_id', $company->id)->lockForUpdate()->first()
                : null;
            if ($shipment === null) {
                $log->update(['status' => 'failed', 'error' => 'بوليصة غير موجودة: '.($awb !== null ? (string) $awb : '—')]);

                return ['http' => 200, 'body' => ['received' => false, 'message' => 'shipment not found']];
            }

            $status = $this->mapStatus($payload);
            if ($status === null) {
                $log->update(['status' => 'ignored', 'error' => 'حالة شحن غير معروفة في الحمولة']);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            // ترتيب الحالات: لا تراجع من حالة نهائية ولا في سلسلة التقدم (H6)
            if (! $this->transitionAllowed($shipment->status, $status)) {
                $log->update(['status' => 'ignored', 'error' => "حدث متأخر/متراجع: {$shipment->status->value} ← {$status->value}"]);

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

            $log->update(['status' => 'processed', 'error' => $note]);

            return ['http' => 200, 'body' => ['received' => true]];
        });
    }

    private function transitionAllowed(ShipmentStatus $current, ShipmentStatus $new): bool
    {
        if ($current === $new) {
            return true;
        }
        // الحالات النهائية لا تتحرك
        if (in_array($current, [ShipmentStatus::Delivered, ShipmentStatus::Returned], true)) {
            return false;
        }
        // الفشل قد يحدث في أي مرحلة، ومن الفشل يمكن استئناف التقدم (إعادة محاولة التسليم)
        if ($new === ShipmentStatus::Failed || $current === ShipmentStatus::Failed) {
            return true;
        }
        // الإرجاع مسموح من أي مرحلة غير نهائية
        if ($new === ShipmentStatus::Returned) {
            return true;
        }

        return (self::RANK[$new->value] ?? 0) >= (self::RANK[$current->value] ?? 0);
    }

    /**
     * مفتاح حدث فعّال: معرّف حدث حقيقي فقط (id الشحنة ثابت في كل تحديثاتها
     * فلا يصلح مفتاحًا)، وإلا مركّب awb|status|timestamp، وإلا بصمة الجسم.
     */
    private function effectiveEventId(array $payload, string $rawBody): string
    {
        $eventId = $this->firstValue($payload, ['event_id', 'eventId', 'data.event_id']);
        if ($eventId !== null) {
            return (string) $eventId;
        }

        $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number']);
        $status = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status']);
        $timestamp = $this->firstValue($payload, ['timestamp', 'occurred_at', 'updated_at', 'data.timestamp', 'data.updated_at']);
        if ($awb !== null && $status !== null && $timestamp !== null) {
            return $awb.'|'.$status.'|'.$timestamp;
        }

        return 'sha256:'.hash('sha256', $rawBody);
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

    private function startLog(string $provider, string $eventId, array $payload, ?string $ip): WebhookLog
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
        return WebhookLog::query()
            ->where('provider', $provider)
            ->where('event_id', $current->event_id)
            ->where('id', '!=', $current->id)
            ->where('status', 'processed')
            ->exists();
    }
}
