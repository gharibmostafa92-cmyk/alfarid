<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookLog;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound payment gateway webhook processing service:
 * - Logs payload fingerprint before signature verification to guard against malformed data.
 * - Validates cryptographic signature per configured driver (HMAC, Paymob, Moyasar, Stripe).
 * - Serializes processing atomically via PostgreSQL advisory lock on (provider:event_id).
 * - Validates billed currency and amount against order grand total before updating status.
 * - Automatically transitions pending orders to Confirmed upon successful payment capture.
 */
class PaymentWebhookService
{
    private const SUCCESS = ['paid', 'success', 'successful', 'succeeded', 'captured', 'completed', 'approved'];

    private const FAILED = ['failed', 'voided', 'declined', 'error', 'cancelled', 'canceled'];

    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected OrderService $orders,
    ) {}

    /**
     * Process an inbound payment gateway webhook request.
     *
     * @param  string  $gateway
     * @param  array<string, mixed>  $payload
     * @param  string  $rawBody
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $query
     * @param  string|null  $ip
     * @return array{http: int, body: array<string, mixed>}
     */
    public function handle(string $gateway, array $payload, string $rawBody, array $headers, array $query, ?string $ip): array
    {
        $provider = 'payment:'.$gateway;
        // Pre-verification key uses raw body hash because unverified payload fields cannot be trusted
        $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);

        // Reject oversized payloads early without storing unverified content
        if (strlen($rawBody) > 262144) {
            $this->slimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $config = config("webhooks.payment.{$gateway}");
        if (! is_array($config)) {
            $this->slimLog($provider, $preVerifyKey, 'failed', 'بوابة دفع غير معروفة', $ip);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown gateway']];
        }

        // Paymob signs fields within the 'obj' dictionary; status, amount, and order IDs must be read from obj
        $isPaymob = (string) ($config['driver'] ?? 'hmac') === 'paymob';

        // Verify cryptographic signature before persisting payload in database
        $verified = $this->verifier->verifyGateway(
            (string) ($config['driver'] ?? 'hmac'),
            $config['secret'] ?? null,
            $rawBody,
            $headers,
            $query,
            $payload,
            $config['signature_header'] ?? null,
        );
        if (! $verified) {
            $this->slimLog($provider, $preVerifyKey, 'invalid_signature', null, $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        // Combine extracted event ID with outcome so subsequent refunds on the same transaction are not dropped as duplicates
        $outcome = $isPaymob ? $this->paymobOutcome($payload) : $this->outcome($payload);
        $extractedId = $isPaymob ? $this->paymobEventId($payload) : $this->extractEventId($payload);
        $eventId = $extractedId !== null ? $extractedId.':'.$outcome : $preVerifyKey;

        $log = $this->startLog($provider, $eventId, $payload, $ip);
        $log->update(['signature_valid' => true]);

        return DB::transaction(function () use ($gateway, $provider, $eventId, $payload, $log, $config, $outcome, $isPaymob): array {
            // Advisory lock serializes concurrent webhooks for the same provider and event key
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$provider.':'.$eventId]);

            if ($this->alreadyProcessed($provider, $log)) {
                $log->update(['status' => 'duplicate']);

                return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
            }

            // Paymob references orders via obj.order.id mapped to orders.gateway_order_id
            $orderReference = $isPaymob
                ? $this->paymobGatewayOrderId($payload)
                : $this->extractOrderNumber($payload);
            $order = $orderReference !== null
                ? Order::query()
                    ->when($isPaymob,
                        fn ($q) => $q->where('gateway_order_id', $orderReference),
                        fn ($q) => $q->where('order_number', $orderReference))
                    ->lockForUpdate()->first()
                : null;
            if ($order === null) {
                $log->update(['status' => 'failed', 'error' => 'طلب غير موجود: '.($orderReference ?? '—')]);

                return ['http' => 200, 'body' => ['received' => false, 'message' => 'order not found']];
            }

            if ($order->payment_method === 'wallet') {
                $log->update(['status' => 'ignored', 'error' => 'Wallet payments are settled internally.']);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            if ($outcome === 'ignored' || $outcome === 'pending') {
                $log->update([
                    'status' => 'ignored',
                    'error' => $outcome === 'pending' ? 'معاملة معلقة لدى البوابة (pending)' : 'حالة دفع غير معروفة في الحمولة',
                ]);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            // Validate that the paid amount and currency match order requirements
            $amountOk = $isPaymob
                ? $this->paymobAmountMatches($payload, $order, $config)
                : $this->amountMatches($payload, $order, $config);
            if ($outcome === 'paid' && ! $amountOk) {
                // If money was captured with an unexpected amount, flag the order for staff investigation
                $order->update(['needs_attention' => true]);
                $log->update(['status' => 'failed', 'error' => 'amount mismatch']);
                Log::warning('Payment amount/currency mismatch on a paid event.', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'gateway' => $gateway,
                    'expected' => (string) $order->grand_total,
                ]);

                return ['http' => 200, 'body' => ['received' => false, 'message' => 'amount mismatch']];
            }

            $note = null;
            if ($outcome === 'paid') {
                if ($order->status === OrderStatus::Cancelled) {
                    // Payment captured after order cancellation: record payment and mark for refund without reviving order
                    $order->update(['payment_status' => 'paid', 'requires_refund' => true]);
                    $note = 'دفع ناجح على طلب ملغي — يحتاج استردادًا';
                } else {
                    $order->update(['payment_status' => 'paid']);
                    if ($order->status === OrderStatus::Pending) {
                        try {
                            $this->orders->changeStatus($order, OrderStatus::Confirmed, null, "تأكيد تلقائي بعد نجاح الدفع عبر {$gateway}");
                        } catch (DeadlockException $e) {
                            // Re-throw database deadlock so the gateway retries delivery
                            throw $e;
                        } catch (Throwable $e) {
                            // Captured payment with confirmation failure requires visible staff attention
                            Log::warning('Payment captured but order auto-confirm failed.', [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'gateway' => $gateway,
                                'exception' => $e->getMessage(),
                            ]);
                            $order->update(['needs_attention' => true]);
                            $note = 'تم الدفع لكن تأكيد الطلب فشل: '.$e->getMessage();
                        }
                    }
                }
            } elseif ($outcome === 'failed') {
                if ($order->payment_status === 'paid') {
                    // Ignore late failure notification if the order was already confirmed paid
                    $log->update(['status' => 'ignored', 'error' => 'حدث فشل متأخر بعد دفع ناجح — يُتجاهل']);

                    return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
                }
                $order->update(['payment_status' => 'failed']);
            } else { // refunded
                // Mark non-cancelled orders for staff review and block shipping dispatch
                $order->update([
                    'payment_status' => 'refunded',
                    'needs_attention' => $order->status !== OrderStatus::Cancelled,
                ]);
                Log::warning('Payment refunded for a non-cancelled order.', [
                    'order_number' => $order->order_number,
                    'gateway' => $gateway,
                ]);
                if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                    $note = 'استرداد على طلب مشحون/مُسلَّم — يحتاج مراجعة';
                } else {
                    $note = 'تم استرداد الدفع — الطلب موسوم للانتباه ولن يخرج للشحن قبل مراجعة الدفع';
                }
            }

            $log->update(['status' => 'processed', 'error' => $note]);

            return ['http' => 200, 'body' => ['received' => true]];
        });
    }

    /**
     * Determine normalized payment outcome from gateway payload (paid, failed, refunded, pending, or ignored).
     */
    private function outcome(array $payload): string
    {
        // Pending status (e.g. Paymob success=false with pending=true) represents uncompleted payment rather than failure
        $pending = data_get($payload, 'obj.pending') ?? data_get($payload, 'data.pending') ?? data_get($payload, 'pending');
        if ($pending === true) {
            return 'pending';
        }

        $successFlag = data_get($payload, 'obj.success') ?? data_get($payload, 'data.success') ?? data_get($payload, 'success');
        if (is_bool($successFlag)) {
            return $successFlag ? 'paid' : 'failed';
        }

        $status = strtolower((string) ($this->firstValue($payload, [
            'status', 'payment_status', 'data.status',
            'data.object.payment_status', 'data.object.status', 'obj.status', 'state',
        ]) ?? ''));
        if (in_array($status, self::SUCCESS, true)) {
            return 'paid';
        }
        if ($status === 'refunded' || $status === 'refund') {
            return 'refunded';
        }
        if (in_array($status, self::FAILED, true)) {
            return 'failed';
        }

        // Stripe events specify the outcome in the event 'type' attribute rather than an inner status field
        $type = strtolower((string) ($this->firstValue($payload, ['type', 'event_type', 'event']) ?? ''));
        if ($type !== '') {
            if (str_contains($type, 'refund')) {
                return 'refunded';
            }
            if (str_contains($type, 'fail') || str_contains($type, 'declin')) {
                return 'failed';
            }
            if (str_contains($type, 'succeeded') || str_contains($type, 'completed')
                || str_contains($type, 'captured') || str_contains($type, 'paid')) {
                return 'paid';
            }
        }

        return 'ignored';
    }

    /**
     * Verify that the webhook billed amount and currency match order requirements within a 1-cent tolerance.
     *
     * @param  array<string, mixed>  $config
     */
    private function amountMatches(array $payload, Order $order, array $config): bool
    {
        $expectedCurrency = strtoupper((string) ($config['currency'] ?? 'EGP'));
        $currency = $this->firstValue($payload, [
            'currency', 'data.currency', 'data.object.currency', 'obj.currency', 'obj.order.currency',
        ]);
        if ($currency !== null && strtoupper((string) $currency) !== $expectedCurrency) {
            return false;
        }

        $amount = $this->firstValue($payload, [
            'amount', 'data.amount', 'data.object.amount', 'data.object.amount_total',
            'data.object.amount_received', 'obj.amount', 'obj.order.amount',
        ]);
        $divisor = (float) ($config['amount_divisor'] ?? 1);
        if ($amount === null) {
            $cents = $this->firstValue($payload, [
                'amount_cents', 'data.amount_cents', 'data.object.amount_total',
                'data.object.amount_received', 'obj.amount_cents', 'obj.order.amount_cents',
            ]);
            if ($cents !== null) {
                $amount = $cents;
                $divisor = 100.0;
            }
        }
        if ($amount === null) {
            // Respect gateway configuration specifying whether an explicit amount is strictly required
            return ! ($config['require_amount'] ?? false);
        }

        return abs(round(((float) $amount) / $divisor, 2) - (float) $order->grand_total) < 0.01;
    }

    private function extractOrderNumber(array $payload): ?string
    {
        $value = $this->firstValue($payload, [
            'order_number', 'merchant_order_id', 'data.order_number',
            'data.object.metadata.order_number', 'data.object.client_reference_id',
            'obj.order.merchant_order_id', 'obj.merchant_order_id', 'metadata.order_number',
        ]);

        return is_scalar($value) ? mb_substr((string) $value, 0, 191) : null;
    }

    private function extractEventId(array $payload): ?string
    {
        $value = $this->firstValue($payload, [
            'event_id', 'transaction_id', 'obj.id', 'data.id', 'data.transaction_id',
            'data.object.id', 'id',
        ]);

        return is_scalar($value) ? mb_substr((string) $value, 0, 191) : null;
    }

    /**
     * Retrieve signed transaction dictionary from Paymob payload.
     */
    private function paymobObj(array $payload): ?array
    {
        $obj = $payload['obj'] ?? null;

        return is_array($obj) ? $obj : null;
    }

    /**
     * Derive Paymob event outcome strictly from signed fields.
     *
     * Refund and void flags are evaluated before success because Paymob sets success=true on refund events.
     */
    private function paymobOutcome(array $payload): string
    {
        $obj = $this->paymobObj($payload);
        if ($obj === null) {
            return 'ignored';
        }

        if ($this->paymobFlag($obj, 'is_refunded') || $this->paymobFlag($obj, 'is_voided')) {
            return 'refunded';
        }
        if ($this->paymobFlag($obj, 'pending')) {
            return 'pending';
        }

        $success = $obj['success'] ?? null;
        if ($success === true || $success === 'true' || $success === 1 || $success === '1') {
            return 'paid';
        }
        if ($success === false || $success === 'false' || $success === 0 || $success === '0') {
            return 'failed';
        }

        return 'ignored';
    }

    /**
     * Extract event ID from Paymob signed obj.id.
     */
    private function paymobEventId(array $payload): ?string
    {
        $obj = $this->paymobObj($payload);
        $id = $obj['id'] ?? null;

        return is_scalar($id) && $id !== '' ? mb_substr((string) $id, 0, 191) : null;
    }

    /**
     * Extract order ID from Paymob signed obj.order.id.
     */
    private function paymobGatewayOrderId(array $payload): ?string
    {
        $obj = $this->paymobObj($payload);
        $id = is_array($obj['order'] ?? null) ? ($obj['order']['id'] ?? null) : null;

        return is_scalar($id) && $id !== '' ? mb_substr((string) $id, 0, 191) : null;
    }

    /**
     * Verify Paymob payment amount against signed obj.amount_cents and obj.currency fields.
     *
     * @param  array<string, mixed>  $config
     */
    private function paymobAmountMatches(array $payload, Order $order, array $config): bool
    {
        $obj = $this->paymobObj($payload);
        if ($obj === null) {
            return false;
        }

        $expectedCurrency = strtoupper((string) ($config['currency'] ?? 'EGP'));
        $currency = $obj['currency'] ?? null;
        if (! is_scalar($currency) || strtoupper((string) $currency) !== $expectedCurrency) {
            return false;
        }

        $cents = $obj['amount_cents'] ?? null;
        if (! is_numeric($cents)) {
            return ! ($config['require_amount'] ?? false);
        }

        return abs(round(((float) $cents) / 100, 2) - (float) $order->grand_total) < 0.01;
    }

    /** @param array<string, mixed> $obj */
    private function paymobFlag(array $obj, string $key): bool
    {
        $value = $obj[$key] ?? null;

        return $value === true || $value === 'true' || $value === 1 || $value === '1';
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
            // Sanitize sensitive credentials (e.g. secret_token) before persisting
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
