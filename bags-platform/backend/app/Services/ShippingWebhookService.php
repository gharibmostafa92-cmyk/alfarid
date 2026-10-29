<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\WebhookLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inbound shipping carrier webhook processing service:
 * - Logs payload fingerprint before signature verification to guard against malformed data.
 * - Validates HMAC signature using the carrier's configured webhook secret.
 * - Serializes processing atomically via PostgreSQL advisory locks on (provider:event_id).
 * - Enforces status progression rules to ignore stale or regressive out-of-order events.
 * - Updates shipment records and automatically triggers order delivery or flags for manual review.
 */
class ShippingWebhookService
{
    /** Common carrier status aliases mapped to internal shipment enum cases. */
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

    /** Lifecycle hierarchy rank — events with lower rank than current state represent out-of-order deliveries. */
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

    /**
     * Process an inbound carrier tracking webhook event.
     *
     * @param  string  $companyCode
     * @param  array<string, mixed>  $payload
     * @param  string  $rawBody
     * @param  string|null  $signature
     * @param  string|null  $ip
     * @return array{http: int, body: array<string, mixed>}
     */
    public function handle(string $companyCode, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $provider = 'shipping:'.$companyCode;
        // Pre-verification key uses raw body hash because unverified payload fields cannot be trusted
        $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);

        if (strlen($rawBody) > 262144) {
            $this->slimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $company = ShippingCompany::query()->where('code', $companyCode)->first();
        if ($company === null || ! $company->is_active) {
            // Generic 403 response prevents carrier code enumeration via distinct HTTP response codes
            $this->slimLog($provider, $preVerifyKey, 'failed', 'شركة شحن غير معروفة أو معطّلة', $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        // Validate HMAC signature exclusively using the carrier's designated webhook_secret
        $credentials = $company->api_credentials ?? [];
        $secret = $credentials['webhook_secret'] ?? null;
        if (! is_string($secret) || $secret === '' || ! $this->verifier->verify($secret, $rawBody, $signature)) {
            $this->slimLog($provider, $preVerifyKey, 'invalid_signature',
                (! is_string($secret) || $secret === '') ? 'لا يوجد webhook_secret مضبوط للشركة' : null, $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        // Derive sanitized event identifier after cryptographic verification succeeds
        $eventId = $this->effectiveEventId($payload, $rawBody);
        $log = $this->startLog($provider, $eventId, $payload, $ip);
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

            // Reject regressive status transitions or movements out of terminal states
            if (! $this->transitionAllowed($shipment->status, $status)) {
                $log->update(['status' => 'ignored', 'error' => "حدث متأخر/متراجع: {$shipment->status->value} ← {$status->value}"]);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            $shipment->update(['status' => $status]);

            $note = null;
            $failed = false;
            if ($status === ShipmentStatus::Returned) {
                // Return notification marks order for attention; physical stock re-entry happens upon manual warehouse intake
                $order = $shipment->order;
                if ($order !== null && in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                    $order->update(['needs_attention' => true]);
                    $note = 'أبلغت شركة الشحن بمرتجع — بانتظار استلام المستودع الفعلي قبل إعادة البضاعة للمخزون';
                }
            }
            if ($status === ShipmentStatus::Failed) {
                // Mark order for staff review unless a newer replacement shipment is already active
                $order = $shipment->order;
                if ($order !== null && ! $this->hasNewerActiveShipment($shipment)) {
                    $order->update(['needs_attention' => true]);
                    $note = 'فشلت البوليصة لدى شركة الشحن — يحتاج متابعة أو إصدار بوليصة بديلة';
                } else {
                    $note = 'بلاغ فشل لبوليصة استُبدلت بأحدث منها — يُتجاهل';
                }
            }
            if ($status === ShipmentStatus::Delivered) {
                $order = $shipment->order;
                if ($order !== null && $order->status === OrderStatus::Shipped) {
                    try {
                        // Order delivery transitions settle cash-on-delivery payments automatically
                        $this->orders->changeStatus($order, OrderStatus::Delivered, null, 'تم التسليم — إشعار شركة الشحن');
                    } catch (Throwable $e) {
                        // Re-throw transient database exceptions so the webhook provider retries delivery
                        if ($this->isTransient($e)) {
                            throw $e;
                        }
                        // Non-transient errors flag the order for staff intervention without dropping the event
                        $order->update(['needs_attention' => true]);
                        $failed = true;
                        $note = 'تم تسليم البوليصة لكن تحويل الطلب فشل: '.$e->getMessage();
                    }
                }
            }

            $log->update(['status' => $failed ? 'failed' : 'processed', 'error' => $note]);

            return ['http' => 200, 'body' => ['received' => true]];
        });
    }

    /**
     * Determine whether an exception is a transient concurrency or connection error eligible for webhook retry.
     */
    private function isTransient(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        // Postgres codes: 55P03 = lock timeout, 40P01 = deadlock, 08006/08003 = connection failure
        return in_array($e->errorInfo[0] ?? $e->getCode(), ['55P03', '40P01', '08006', '08003'], true);
    }

    /**
     * Enforce status state machine rules to prevent lifecycle regression.
     */
    private function transitionAllowed(ShipmentStatus $current, ShipmentStatus $new): bool
    {
        if ($current === $new) {
            return true;
        }
        // Terminal states cannot transition further
        if (in_array($current, [ShipmentStatus::Delivered, ShipmentStatus::Returned], true)) {
            return false;
        }
        // Failed shipments may resume progression on redelivery attempts
        if ($new === ShipmentStatus::Failed || $current === ShipmentStatus::Failed) {
            return true;
        }
        // Returns are permissible from any non-terminal state
        if ($new === ShipmentStatus::Returned) {
            return true;
        }

        return (self::RANK[$new->value] ?? 0) >= (self::RANK[$current->value] ?? 0);
    }

    /**
     * Build an effective deduplication key by combining event ID with normalized status.
     *
     * Prevents carriers that reuse event IDs for subsequent milestone updates from being dropped as duplicates.
     */
    private function effectiveEventId(array $payload, string $rawBody): string
    {
        $eventId = $this->firstValue($payload, ['event_id', 'eventId', 'data.event_id']);
        if (is_scalar($eventId)) {
            $status = $this->rawStatus($payload);
            $suffix = $status !== null ? ':'.$status : '';

            return mb_substr((string) $eventId.$suffix, 0, 191);
        }

        $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number']);
        $status = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status']);
        $timestamp = $this->firstValue($payload, ['timestamp', 'occurred_at', 'updated_at', 'data.timestamp', 'data.updated_at']);
        if (is_scalar($awb) && is_scalar($status) && is_scalar($timestamp)) {
            return mb_substr($awb.'|'.$status.'|'.$timestamp, 0, 191);
        }

        return 'sha256:'.hash('sha256', $rawBody);
    }

    /**
     * Extract and normalize raw status string for key generation.
     */
    private function rawStatus(array $payload): ?string
    {
        $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
        if (! is_scalar($raw)) {
            return null;
        }

        return $this->mapStatus($payload)?->value
            ?? str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));
    }

    private function mapStatus(array $payload): ?ShipmentStatus
    {
        $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
        if (! is_scalar($raw)) {
            return null;
        }
        $key = str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));

        return self::STATUS_MAP[$key] ?? null;
    }

    /**
     * Check whether a newer active shipment exists for the order to ignore stale failure events.
     */
    private function hasNewerActiveShipment(Shipment $shipment): bool
    {
        return Shipment::query()
            ->where('order_id', $shipment->order_id)
            ->where('id', '>', $shipment->id)
            ->whereNotIn('status', [
                ShipmentStatus::Failed->value,
                ShipmentStatus::Returned->value,
                ShipmentStatus::Delivered->value,
            ])
            ->exists();
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
            // Sanitize sensitive credentials before persisting webhook body
            'payload' => WebhookLog::sanitizePayload($payload),
            'status' => 'received',
            'ip_address' => $ip,
        ]);
    }

    /**
     * Create a lightweight log entry for rejected requests without storing untrusted payload bytes.
     */
    private function slimLog(string $provider, string $eventId, string $status, ?string $error, ?string $ip): void
    {
        WebhookLog::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'payload' => null,
            'signature_valid' => false,
            'status' => $status,
            'error' => $error,
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
