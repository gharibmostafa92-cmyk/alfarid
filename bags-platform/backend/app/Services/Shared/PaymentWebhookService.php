<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Enums\Orders\OrderStatus;
use App\Models\Order;
use App\Models\WebhookLog;
use App\Services\Orders\OrderService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
    public const MAX_BODY_BYTES = 256 * 1024;

    private const SUCCESS = ['paid', 'success', 'successful', 'succeeded', 'captured', 'completed', 'approved'];

    private const FAILED = ['failed', 'voided', 'declined', 'error', 'cancelled', 'canceled'];

    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected OrderService $orders,
        protected PaymobRefundInquiry $paymobRefundInquiry,
    ) {}

    /**
     * Process an inbound payment gateway webhook request under transactional concurrency locks.
     *
     * @param  string  $gateway  Configured gateway identifier (paymob, moyasar, stripe, etc.).
     * @param  array<string, mixed>  $payload  Decoded webhook JSON payload.
     * @param  string  $rawBody  Raw HTTP request body for cryptographic signature verification.
     * @param  array<string, string|null>  $headers  HTTP headers containing signatures or security tokens.
     * @param  array<string, mixed>  $query  Query string parameters.
     * @param  string|null  $ip  Client IP address.
     * @return array{http: int, body: array<string, mixed>} HTTP status code and response payload.
     */
    public function handle(string $gateway, array $payload, string $rawBody, array $headers, array $query, ?string $ip): array
    {
        $gateway = strtolower($gateway);
        $provider = 'payment:'.$gateway;

        // M3: reject malformed gateway names before writing anything. The route
        // already constrains the slug, but the service is also called directly.
        if (preg_match('/\A[a-z0-9_-]{1,32}\z/', $gateway) !== 1) {
            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown gateway']];
        }

        // Pre-verification key uses raw body hash because unverified payload fields cannot be trusted
        $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);

        // Reject oversized payloads early without storing unverified content
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $this->sampledSlimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $config = config("webhooks.payment.{$gateway}");
        if (! is_array($config)) {
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
            $this->sampledSlimLog($provider, $preVerifyKey, 'invalid_signature', null, $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        // Combine extracted event ID with outcome so subsequent refunds on the same transaction are not dropped as duplicates
        $outcome = $isPaymob ? $this->paymobOutcome($payload) : $this->outcome($payload);
        $extractedId = $isPaymob ? $this->paymobEventId($payload) : $this->extractEventId($payload, $outcome);

        // M2: a gateway may deliver several successful events for one payment
        // (charge.succeeded, payment_intent.succeeded, checkout.session.completed).
        // Only the configured subset is processed; everything else is ignored.
        $allowedEvents = $config['allowed_events'] ?? null;
        if (is_array($allowedEvents) && $allowedEvents !== []) {
            $eventType = strtolower((string) ($this->firstValue($payload, ['type', 'event_type', 'event']) ?? ''));
            $allowed = array_map(fn ($type) => strtolower((string) $type), $allowedEvents);
            if (! in_array($eventType, $allowed, true)) {
                $eventId = $this->buildEventId($extractedId ?? $preVerifyKey, $outcome, null);
                $log = $this->startLog($provider, $eventId, $payload, $ip);
                $log->update([
                    'signature_valid' => true,
                    'status' => 'ignored',
                    'error' => 'event type not subscribed: '.mb_substr($eventType, 0, 191),
                ]);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }
        }

        $refundAmount = null;
        if ($outcome === 'refunded') {
            // The callback HMAC does not authenticate Paymob's cumulative refund total.
            $refundAmount = $isPaymob
                ? $this->paymobRefundInquiry->amount($extractedId, $this->paymobGatewayOrderId($payload), $config)
                : $this->refundAmount($payload, $config);
        }
        $eventId = $extractedId === null && $outcome !== 'refunded'
            ? $preVerifyKey
            : $this->buildEventId($extractedId ?? $preVerifyKey, $outcome, $refundAmount);

        if ($isPaymob && $outcome === 'refunded' && $refundAmount === null) {
            // L1: a failed refund inquiry (auth error, timeout) leaves the
            // cumulative refund total unknown; marking processed would lose the
            // refund and 200 would stop redelivery. Fail transiently instead.
            $this->slimLog($provider, $eventId, 'failed', 'refund inquiry unavailable', $ip);

            return ['http' => 503, 'body' => ['received' => false, 'message' => 'refund inquiry unavailable']];
        }

        if (WebhookLog::query()->where('provider', $provider)->where('event_id', $eventId)->where('status', 'processed')->exists()) {
            // M3: a redelivery of an already-processed event must not store
            // another full payload row; a sampled slim entry is enough.
            $this->sampledSlimLog($provider, $eventId, 'duplicate', null, $ip);

            return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
        }

        // M3: the Paymob HMAC covers obj only; persist obj (+type) instead of
        // the full payload with attacker-controlled extra keys.
        $storedPayload = $isPaymob
            ? ['obj' => $payload['obj'] ?? null, 'type' => $payload['type'] ?? null]
            : $payload;
        $log = $this->startLog($provider, $eventId, $storedPayload, $ip);
        $log->update(['signature_valid' => true]);

        try {
            return DB::transaction(function () use ($gateway, $provider, $eventId, $payload, $log, $config, $outcome, $isPaymob, $refundAmount): array {
                // Advisory lock serializes concurrent webhooks for the same provider and event key
                DB::statement('SELECT pg_advisory_xact_lock(101, hashtext(?))', [$provider.':'.$eventId]);

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
                    // Non-2xx so the gateway redelivers once the order commit becomes visible.
                    return ['http' => 409, 'body' => ['received' => false, 'message' => 'order not found']];
                }

                if (in_array($order->payment_method, ['wallet', 'bank_transfer'], true)) {
                    $log->update(['status' => 'ignored', 'error' => 'This payment method is settled outside the gateway.']);

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
                    if ($order->payment_status === 'refunded') {
                        // WH-H2: البوابات لا تضمن ترتيب التسليم — paid متأخر يجب ألا يقلب
                        // مستردًا إلى paid؛ يُرفع للمراجعة البشرية بلا تغيير حالة.
                        $order->update(['needs_attention' => true]);
                        $note = 'حدث دفع ناجح على طلب مسترد مسبقًا — تحقق من التحصيل قبل الشحن';
                    } elseif ($order->payment_status === 'paid') {
                        // WH-H2: حدث ناجح ثاني بمعرّف مختلف = تحصيل مزدوج محتمل لا يلاحظه أحد.
                        $order->update(['needs_attention' => true]);
                        $note = 'حدث دفع ناجح مكرر لطلب مدفوع مسبقًا — تحقق من تحصيل مزدوج';
                    } elseif ($order->status === OrderStatus::Cancelled) {
                        // Payment captured after order cancellation: record payment and mark for refund without reviving order
                        $order->update(['payment_status' => 'paid', 'requires_refund' => true, 'needs_attention' => true]);
                        $note = 'دفع ناجح على طلب ملغي — يحتاج استردادًا';
                    } else {
                        $order->update(['payment_status' => 'paid']);
                        if ($order->status === OrderStatus::Pending) {
                            try {
                                // Nested transaction is a savepoint. A constraint failure inside
                                // changeStatus aborts only that savepoint (25P02), so the captured
                                // payment can still be flagged instead of returning 500.
                                DB::transaction(function () use ($order, $gateway): void {
                                    $this->orders->changeStatus($order, OrderStatus::Confirmed, null, "تأكيد تلقائي بعد نجاح الدفع عبر {$gateway}");
                                });
                            } catch (DeadlockException $e) {
                                // Re-throw database deadlock so the gateway retries delivery
                                throw $e;
                            } catch (QueryException $e) {
                                $state = (string) ($e->errorInfo[0] ?? $e->getCode());
                                if (in_array($state, ['55P03', '40P01', '08006', '08003'], true)) {
                                    throw $e;
                                }
                                // WH-L5: رسالة الاستثناء قد تحمل نص SQL وقيماً — تُسجَّل كاملة
                                // في السجل فقط، والملاحظة المعروضة للموظفين تبقى عامة.
                                report($e);
                                Log::warning('Payment captured but order auto-confirm failed.', [
                                    'order_id' => $order->id,
                                    'order_number' => $order->order_number,
                                    'gateway' => $gateway,
                                    'sqlstate' => $state,
                                ]);
                                $order->refresh();
                                $order->update(['needs_attention' => true]);
                                $note = 'تم الدفع لكن تأكيد الطلب فشل — راجع سجلات النظام';
                            } catch (Throwable $e) {
                                // Captured payment with confirmation failure requires visible staff attention
                                report($e);
                                Log::warning('Payment captured but order auto-confirm failed.', [
                                    'order_id' => $order->id,
                                    'order_number' => $order->order_number,
                                    'gateway' => $gateway,
                                ]);
                                $order->refresh();
                                $order->update(['needs_attention' => true]);
                                $note = 'تم الدفع لكن تأكيد الطلب فشل — راجع سجلات النظام';
                            }
                        }
                    }
                } elseif ($outcome === 'failed') {
                    if ($order->payment_status === 'paid') {
                        // Ignore late failure notification if the order was already confirmed paid
                        $log->update(['status' => 'ignored', 'error' => 'حدث فشل متأخر بعد دفع ناجح — يُتجاهل']);

                        return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
                    }
                    if ($order->payment_status === 'refunded') {
                        // WH-H2: فشل متأخر لا يمحو حقيقة استرداد مُثبت.
                        $log->update(['status' => 'ignored', 'error' => 'حدث فشل على طلب مسترد — يُتجاهل']);

                        return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
                    }
                    $order->update(['payment_status' => 'failed']);
                } else { // refunded
                    if (! $this->refundCurrencyMatches($payload, $config)) {
                        // M9: a refund in another currency must never flip the order
                        // to refunded; staff have to reconcile it manually.
                        $order->update(['needs_attention' => true]);
                        $note = 'استرداد بعملة مختلفة عن عملة الطلب — لم تُحدَّث حالة الدفع';
                        Log::warning('Refund currency mismatch.', [
                            'order_number' => $order->order_number,
                            'gateway' => $gateway,
                        ]);
                    } elseif (! in_array($order->payment_status, ['paid', 'refunded'], true)) {
                        // M9: money that was never captured cannot be refunded.
                        $order->update(['needs_attention' => true]);
                        $note = 'استرداد على طلب غير مدفوع — يحتاج مراجعة';
                        Log::warning('Refund received for an unpaid order.', [
                            'order_number' => $order->order_number,
                            'gateway' => $gateway,
                            'payment_status' => $order->payment_status,
                        ]);
                    } elseif ($refundAmount === null || ! $this->sameMoney($refundAmount, (float) $order->grand_total)) {
                        $order->update(['needs_attention' => true]);
                        $note = 'مبلغ الاسترداد غير مؤكد أو لا يطابق إجمالي الطلب — يحتاج مراجعة';
                    } else {
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
                }

                $log->update(['status' => 'processed', 'error' => $note]);

                return ['http' => 200, 'body' => ['received' => true]];
            });
        } catch (Throwable $e) {
            // M9: the in-transaction status update is rolled back, so failure
            // must be recorded through a fresh write outside the transaction.
            WebhookLog::query()->whereKey($log->getKey())->update([
                'status' => 'error',
                'error' => mb_substr('processing error: '.class_basename($e), 0, 255),
            ]);

            throw $e;
        }
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

        // Stripe charge.refunded carries data.object.status=succeeded. The event type
        // is the refund signal and must win before that inner charge status.
        $type = strtolower((string) ($this->firstValue($payload, ['type', 'event_type', 'event']) ?? ''));
        if ($type !== '' && str_contains($type, 'refund')) {
            return 'refunded';
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
        if ($status !== '') {
            // WH-H1: حالة صريحة وغير معروفة (مثل unpaid على checkout.session.completed
            // لوسيلة دفع مؤجلة) لا يجوز ترقيتها إلى paid عبر استدلال النوع — استنتاج
            // الفشل المعلن فقط يظل مسموحًا، وغير ذلك يُصنَّف ignored لمراجعة بشرية.
            $type = strtolower((string) ($this->firstValue($payload, ['type', 'event_type', 'event']) ?? ''));
            if ($type !== '' && (str_contains($type, 'fail') || str_contains($type, 'declin'))) {
                return 'failed';
            }

            return 'ignored';
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
            // WH-M8: الغياب التام للمبلغ لا يؤكد الدفع افتراضيًا — البوابات التي لا
            // ترسل مبالغ تحتاج ضبط require_amount=false صريحًا في config/webhooks.php.
            return ! ($config['require_amount'] ?? true);
        }

        return $this->sameMoney(((float) $amount) / $divisor, (float) $order->grand_total);
    }

    /**
     * Extract the refunded amount from a refund webhook payload, if the gateway reports one.
     *
     * Stripe charge objects carry amount_refunded in minor units (cents); generic gateways
     * report major units scaled by the configured amount_divisor. Returns null when the
     * payload carries no explicit, verifiable refund amount; callers must treat null as
     * unknown (flag for review), never as a full refund.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    private function refundAmount(array $payload, array $config): ?float
    {
        $type = $this->firstValue($payload, ['type', 'event_type', 'event']);
        // Individual Stripe refund objects can be pending or failed and don't
        // report the cumulative charge total. Reconcile using charge.refunded.
        if (is_string($type) && str_contains(strtolower($type), 'refund.') && $type !== 'charge.refunded') {
            return null;
        }

        $stripeCents = data_get($payload, 'data.object.amount_refunded');
        $amount = $stripeCents ?? $this->firstValue($payload, [
            'amount_refunded', 'data.amount_refunded', 'refunded_amount', 'amount', 'data.amount',
        ]);
        $divisor = $stripeCents !== null ? 100.0 : (float) ($config['amount_divisor'] ?? 1);

        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount <= 0 || $divisor <= 0) {
            return null;
        }

        return (float) $amount / $divisor;
    }

    /** @param array<string, mixed> $config */
    private function refundCurrencyMatches(array $payload, array $config): bool
    {
        $expected = strtoupper((string) ($config['currency'] ?? 'EGP'));
        $currency = $this->firstValue($payload, [
            'currency', 'data.currency', 'data.object.currency', 'obj.currency', 'obj.order.currency',
        ]);

        return $currency === null || strtoupper((string) $currency) === $expected;
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

    private function extractEventId(array $payload, string $outcome): ?string
    {
        // H1: Stripe's delivery id is the stable top-level evt_… id. The nested
        // data.object.id is ch_/pi_ and identical for every event on that charge.
        $topLevel = $payload['id'] ?? null;
        if ($outcome === 'refunded' && is_string($topLevel) && str_starts_with($topLevel, 'evt_')) {
            return mb_substr($topLevel, 0, 191);
        }

        $value = $this->firstValue($payload, [
            'event_id', 'transaction_id', 'obj.id', 'data.id', 'data.transaction_id',
            'data.object.id', 'id',
        ]);

        return is_scalar($value) ? mb_substr((string) $value, 0, 191) : null;
    }

    /**
     * Build the idempotency key, keeping the outcome/amount suffix intact while
     * staying inside webhook_logs.event_id width (191).
     */
    private function buildEventId(string $base, string $outcome, ?float $refundAmount): string
    {
        $suffix = ':'.$outcome;
        if ($refundAmount !== null) {
            $suffix .= ':'.number_format($refundAmount, 2, '.', '');
        }

        return mb_substr($base, 0, max(1, 191 - mb_strlen($suffix))).$suffix;
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

        if ($this->paymobFlag($obj, 'pending')) {
            return 'pending';
        }
        if ($this->paymobFlag($obj, 'is_refunded') || $this->paymobFlag($obj, 'is_voided')) {
            return 'refunded';
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
            // M9: default to requiring the amount, matching the generic path.
            return ! ($config['require_amount'] ?? true);
        }

        return $this->sameMoney(((float) $cents) / 100, (float) $order->grand_total);
    }

    private function sameMoney(float $paid, float $expected): bool
    {
        return (int) round($paid * 100) === (int) round($expected * 100);
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
     * M3: unauthenticated failures must not be able to flood webhook_logs.
     * Keep the first few rows per source/status for observability, then sample.
     */
    private function sampledSlimLog(string $provider, string $eventId, string $status, ?string $error, ?string $ip): void
    {
        $key = 'webhook-log:'.$status.':'.hash('sha256', ($ip ?? 'unknown').'|'.$provider);
        RateLimiter::attempt($key, 30, fn () => $this->slimLog($provider, $eventId, $status, $error, $ip), 60);
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
