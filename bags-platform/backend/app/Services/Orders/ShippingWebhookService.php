<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\WebhookLog;
use App\Services\Shared\WebhookSignatureVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
     * @param  array<string, mixed>  $payload
     * @return array{http: int, body: array<string, mixed>}
     */
    public function handle(string $companyCode, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $companyCode = strtolower($companyCode);
        if (preg_match('/\A[a-z0-9_-]{1,40}\z/', $companyCode) !== 1) {
            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        $provider = 'shipping:'.$companyCode;
        // Pre-verification key uses raw body hash because unverified payload fields cannot be trusted.
        $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);

        $verified = $this->verifyInboundRequest($companyCode, $provider, $preVerifyKey, $rawBody, $signature, $ip);
        if (! $verified instanceof ShippingCompany) {
            return $verified;
        }

        // Derive sanitized event identifier after cryptographic verification succeeds.
        $eventId = $this->effectiveEventId($payload, $rawBody);
        $log = $this->startLog($provider, $eventId, $payload, $ip, true);

        return DB::transaction(fn (): array => $this->processVerifiedWebhook($provider, $payload, $log, $verified));
    }

    /**
     * Validates payload size, carrier status, and HMAC signature before persisting full payloads.
     *
     * @return ShippingCompany|array{http: int, body: array<string, mixed>}
     */
    private function verifyInboundRequest(
        string $companyCode,
        string $provider,
        string $preVerifyKey,
        string $rawBody,
        ?string $signature,
        ?string $ip,
    ): ShippingCompany|array {
        if (strlen($rawBody) > 262144) {
            $this->slimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $company = ShippingCompany::query()
            ->whereRaw('lower(code) = ?', [$companyCode])
            ->first();
        if ($company === null || ! $company->is_active) {
            // Generic 403 response prevents carrier code enumeration via distinct HTTP response codes.
            $this->slimLog($provider, $preVerifyKey, 'failed', 'شركة شحن غير معروفة أو معطّلة', $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        // Validate HMAC signature exclusively using the carrier's designated webhook_secret.
        $credentials = $company->api_credentials ?? [];
        $secret = $credentials['webhook_secret'] ?? null;
        if (! is_string($secret) || $secret === '' || ! $this->verifier->verify($secret, $rawBody, $signature)) {
            $this->slimLog(
                $provider,
                $preVerifyKey,
                'invalid_signature',
                (! is_string($secret) || $secret === '') ? 'لا يوجد webhook_secret مضبوط للشركة' : null,
                $ip,
            );

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        return $company;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{http: int, body: array<string, mixed>}
     */
    private function processVerifiedWebhook(string $provider, array $payload, WebhookLog $log, ShippingCompany $company): array
    {
        DB::statement('SELECT pg_advisory_xact_lock(102, hashtext(?))', [$provider.':'.$log->event_id]);

        if ($this->alreadyProcessed($provider, $log)) {
            $log->update(['status' => 'duplicate']);

            return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
        }

        $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number', 'data.tracking_number']);
        $shipmentRef = $awb !== null
            ? Shipment::query()->where('awb_number', (string) $awb)->where('shipping_company_id', $company->id)->first(['id', 'order_id'])
            : null;
        if ($shipmentRef === null) {
            $log->update(['status' => 'failed', 'error' => 'بوليصة غير موجودة: '.($awb !== null ? (string) $awb : '—')]);

            return ['http' => 200, 'body' => ['received' => false, 'message' => 'shipment not found']];
        }

        // Lock orders -> shipments in the same global order as ShipmentService to prevent deadlocks.
        $order = Order::query()->lockForUpdate()->find($shipmentRef->order_id);
        $shipment = Shipment::query()->whereKey($shipmentRef->id)->lockForUpdate()->first();
        if ($shipment === null) {
            $log->update(['status' => 'failed', 'error' => 'بوليصة غير موجودة: '.($awb !== null ? (string) $awb : '—')]);

            return ['http' => 200, 'body' => ['received' => false, 'message' => 'shipment not found']];
        }

        $status = $this->mapStatus($payload);
        if ($status === null) {
            $log->update(['status' => 'ignored', 'error' => 'حالة شحن غير معروفة في الحمولة']);

            return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
        }

        // Once a newer viable waybill exists, the old shipment is superseded.
        // Ignore every status except Returned (which only annotates that old
        // shipment); a stale Delivered event raises a review flag without
        // settling or changing the order.
        if ($this->hasNewerDeliveredOrActiveShipment($shipment) && $status !== ShipmentStatus::Returned) {
            $deliveryWarning = $status === ShipmentStatus::Delivered;
            if ($deliveryWarning && $order !== null) {
                $order->update(['needs_attention' => true]);
            }
            $log->update([
                'status' => 'ignored',
                'error' => $deliveryWarning
                    ? 'وصل بلاغ تسليم لبوليصة مستبدلة — يلزم التحقق من احتمال ازدواج التسليم'
                    : 'وصل تحديث لبوليصة استُبدلت بأحدث منها — يُتجاهل',
            ]);

            return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
        }

        // Reject regressive status transitions or movements out of terminal states.
        if (! $this->transitionAllowed($shipment->status, $status)) {
            $log->update(['status' => 'ignored', 'error' => "حدث متأخر/متراجع: {$shipment->status->value} ← {$status->value}"]);

            return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
        }

        $shipment->update(['status' => $status]);
        ['failed' => $failed, 'note' => $note] = $this->applyShipmentStatusEffects($shipment, $order, $status);

        $log->update(['status' => $failed ? 'failed' : 'processed', 'error' => $note]);

        return ['http' => 200, 'body' => ['received' => true]];
    }

    /**
     * @return array{failed: bool, note: ?string}
     */
    private function applyShipmentStatusEffects(Shipment $shipment, ?Order $order, ShipmentStatus $status): array
    {
        if ($status === ShipmentStatus::Returned) {
            // Return notification marks order for attention unless a newer replacement shipment is active or delivered.
            if ($order !== null && ! $this->hasNewerDeliveredOrActiveShipment($shipment) && in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                $order->update(['needs_attention' => true]);

                return ['failed' => false, 'note' => 'أبلغت شركة الشحن بمرتجع — بانتظار استلام المستودع الفعلي قبل إعادة البضاعة للمخزون'];
            }

            return ['failed' => false, 'note' => null];
        }

        if ($status === ShipmentStatus::Failed) {
            // Mark order for staff review unless a newer replacement shipment is already active.
            if ($order !== null && ! $this->hasNewerActiveShipment($shipment)) {
                $order->update(['needs_attention' => true]);

                return ['failed' => false, 'note' => 'فشلت البوليصة لدى شركة الشحن — يحتاج متابعة أو إصدار بوليصة بديلة'];
            }

            return ['failed' => false, 'note' => 'بلاغ فشل لبوليصة استُبدلت بأحدث منها — يُتجاهل'];
        }

        if ($status === ShipmentStatus::Delivered) {
            // Keep the order-level delivery clock at the last active parcel.
            // Return eligibility currently uses the order's Delivered timestamp,
            // so marking it on an earlier parcel could shorten the window for
            // items arriving in a later replacement/split shipment.
            if ($order !== null && $order->status === OrderStatus::Shipped && ! $this->hasNewerActiveShipment($shipment)) {
                return $this->deliverOrderFromShipment($order);
            }
        }

        return ['failed' => false, 'note' => null];
    }

    /**
     * @return array{failed: bool, note: ?string}
     */
    private function deliverOrderFromShipment(Order $order): array
    {
        try {
            // Nested transaction is a savepoint. A constraint failure inside
            // changeStatus aborts only that savepoint, so the order can still
            // be flagged and the carrier event is not lost (25P02).
            DB::transaction(function () use ($order): void {
                $this->orders->changeStatus($order, OrderStatus::Delivered, null, 'تم التسليم — إشعار شركة الشحن');
            });

            return ['failed' => false, 'note' => null];
        } catch (Throwable $e) {
            // Re-throw transient database exceptions so the webhook provider retries delivery.
            if ($this->isTransient($e)) {
                throw $e;
            }
            // Non-transient errors flag the order for staff intervention without dropping the event.
            $order->refresh();
            $order->update(['needs_attention' => true]);

            return ['failed' => true, 'note' => 'تم تسليم البوليصة لكن تحويل الطلب فشل: '.$e->getMessage()];
        }
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
        if ($current->isTerminal()) {
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

            return $this->boundedEventKey((string) $eventId.$suffix);
        }

        $awb = $this->firstValue($payload, ['awb_number', 'tracking_number', 'awb', 'data.awb_number']);
        $status = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status']);
        $timestamp = $this->firstValue($payload, ['timestamp', 'occurred_at', 'updated_at', 'data.timestamp', 'data.updated_at']);
        if (is_scalar($awb) && is_scalar($status) && is_scalar($timestamp)) {
            return $this->boundedEventKey($awb.'|'.$status.'|'.$timestamp);
        }

        return 'sha256:'.hash('sha256', $rawBody);
    }

    /**
     * Keep event keys within the database column while preserving distinction at the end.
     */
    private function boundedEventKey(string $key): string
    {
        if (mb_strlen($key, 'UTF-8') <= 191) {
            return $key;
        }

        return mb_substr($key, 0, 126, 'UTF-8').':'.hash('sha256', $key);
    }

    /**
     * Extract and normalize raw status string for key generation.
     */
    private function rawStatus(array $payload): ?string
    {
        $normalized = $this->normalizedRawStatus($payload);
        if ($normalized === null) {
            return null;
        }

        return (self::STATUS_MAP[$normalized] ?? null)?->value ?? $normalized;
    }

    private function mapStatus(array $payload): ?ShipmentStatus
    {
        $normalized = $this->normalizedRawStatus($payload);

        return $normalized !== null ? (self::STATUS_MAP[$normalized] ?? null) : null;
    }

    private function normalizedRawStatus(array $payload): ?string
    {
        $raw = $this->firstValue($payload, ['status', 'state', 'shipment_status', 'data.status', 'data.state']);
        if (! is_scalar($raw)) {
            return null;
        }

        return str_replace([' ', '-'], '_', strtolower(trim((string) $raw)));
    }

    /**
     * Check whether a newer active shipment exists for the order to ignore stale failure events.
     */
    private function hasNewerActiveShipment(Shipment $shipment): bool
    {
        return $this->hasNewerShipmentExcluding($shipment, [
            ShipmentStatus::Failed->value,
            ShipmentStatus::Returned->value,
            ShipmentStatus::Delivered->value,
        ]);
    }

    /**
     * Check whether a newer shipment exists that is either active or already delivered.
     */
    private function hasNewerDeliveredOrActiveShipment(Shipment $shipment): bool
    {
        return $this->hasNewerShipmentExcluding($shipment, [
            ShipmentStatus::Failed->value,
            ShipmentStatus::Returned->value,
        ]);
    }

    /**
     * @param  list<string>  $excludedStatuses
     */
    private function hasNewerShipmentExcluding(Shipment $shipment, array $excludedStatuses): bool
    {
        return Shipment::query()
            ->where('order_id', $shipment->order_id)
            ->where('id', '>', $shipment->id)
            ->whereNotIn('status', $excludedStatuses)
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

    private function startLog(string $provider, string $eventId, array $payload, ?string $ip, bool $signatureValid = false): WebhookLog
    {
        // A transient transaction rollback leaves a verified event in received
        // state. Reuse that row on the carrier retry instead of storing another
        // full copy of the same payload for every retry.
        $received = WebhookLog::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->where('status', 'received')
            ->latest('id')
            ->first();
        if ($received !== null) {
            $received->update([
                'payload' => WebhookLog::sanitizePayload($payload),
                'signature_valid' => $signatureValid,
                'ip_address' => $ip,
                'error' => null,
            ]);

            return $received;
        }

        return WebhookLog::create([
            'provider' => $provider,
            'event_id' => $eventId,
            // Sanitize sensitive credentials before persisting webhook body
            'payload' => WebhookLog::sanitizePayload($payload),
            'status' => 'received',
            'signature_valid' => $signatureValid,
            'ip_address' => $ip,
        ]);
    }

    /**
     * Create a lightweight log entry for rejected requests without storing untrusted payload bytes.
     */
    private function slimLog(string $provider, string $eventId, string $status, ?string $error, ?string $ip): void
    {
        $key = 'shipping-rejected:'.hash('sha256', ($ip ?? 'unknown').'|'.$status);
        RateLimiter::attempt($key, 30, fn () => WebhookLog::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'payload' => null,
            'signature_valid' => false,
            'status' => $status,
            'error' => $error,
            'ip_address' => $ip,
        ]), 60);
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
