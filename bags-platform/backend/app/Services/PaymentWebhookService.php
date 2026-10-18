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
 * معالجة ويب هوك بوابات الدفع:
 * تسجيل أولًا ← تحقق HMAC ← معالجة ذرّية داخل transaction بقفل استشاري
 * على (provider, event) لمنع المعالجة المكررة المتزامنة (H4) ←
 * التحقق من المبلغ والعملة مقابل إجمالي الطلب (H5) ← تحديث حالة الدفع
 * وتأكيد الطلب تلقائيًا عبر OrderService عند النجاح.
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
     * @param  array<string, string|null>  $headers
     * @param  array<string, mixed>  $query
     * @return array{http: int, body: array<string, mixed>}
     */
    public function handle(string $gateway, array $payload, string $rawBody, array $headers, array $query, ?string $ip): array
    {
        $provider = 'payment:'.$gateway;
        // قبل التحقق لا يُوثق بأي حقل من الحمولة (F-07): مفتاح السجل
        // المختصر هو بصمة الجسم فقط، وأي معرّف من الحمولة قد يكون مصفوفة
        // تكسر (string) أو نصًا أطول من عمود السجل فيحوّل الهجوم لـ 500
        $preVerifyKey = 'sha256:'.hash('sha256', $rawBody);

        // حد حجم الجسم أولًا — الرفض بسجل مختصر بلا حمولة (M4)
        if (strlen($rawBody) > 262144) {
            $this->slimLog($provider, $preVerifyKey, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $config = config("webhooks.payment.{$gateway}");
        if (! is_array($config)) {
            $this->slimLog($provider, $preVerifyKey, 'failed', 'بوابة دفع غير معروفة', $ip);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown gateway']];
        }

        // Paymob (C1): توقيعه يغطي حقول obj المحددة فقط، فكل قرار (الحالة،
        // المبلغ، المعرّف، ربط الطلب) يجب أن يُشتق من obj وحدها — لا من حقول
        // أعلى المستوى غير الموقّعة (order_number/amount/event_id).
        $isPaymob = (string) ($config['driver'] ?? 'hmac') === 'paymob';

        // التحقق بنمط البوابة (F-06) قبل تخزين الحمولة: محاولات مجهولة
        // لا تملأ webhook_logs.payload — تُسجل مختصرة فقط (M4)
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

        // بعد التحقق فقط: معرّف الحدث من الحمولة مُنقّى (قيمة مفردة وحد
        // طول) ومدموجًا بنتيجة الحدث (F-08) حتى لا يبتلع الاستردادَ حدثُ
        // الدفعِ لنفس المعاملة فيُعلَّم duplicate
        $outcome = $isPaymob ? $this->paymobOutcome($payload) : $this->outcome($payload);
        $extractedId = $isPaymob ? $this->paymobEventId($payload) : $this->extractEventId($payload);
        $eventId = $extractedId !== null ? $extractedId.':'.$outcome : $preVerifyKey;

        $log = $this->startLog($provider, $eventId, $payload, $ip);
        $log->update(['signature_valid' => true]);

        return DB::transaction(function () use ($gateway, $provider, $eventId, $payload, $log, $config, $outcome, $isPaymob): array {
            // قفل استشاري على مفتاح الحدث: نسختان متزامنتان من نفس الحدث تتسلسلان هنا
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$provider.':'.$eventId]);

            if ($this->alreadyProcessed($provider, $log)) {
                $log->update(['status' => 'duplicate']);

                return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
            }

            // ربط الطلب (C1): لـ Paymob من obj.order.id الموقّع مقابل
            // orders.gateway_order_id، ولباقي البوابات من حقولها المعتادة
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

            if ($outcome === 'ignored' || $outcome === 'pending') {
                $log->update([
                    'status' => 'ignored',
                    'error' => $outcome === 'pending' ? 'معاملة معلقة لدى البوابة (pending)' : 'حالة دفع غير معروفة في الحمولة',
                ]);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            // التحقق من المبلغ والعملة قبل أي تغيير (H5) — ولـ Paymob من
            // obj.amount_cents/obj.currency الموقّعين فقط (C1)
            $amountOk = $isPaymob
                ? $this->paymobAmountMatches($payload, $order, $config)
                : $this->amountMatches($payload, $order, $config);
            if ($outcome === 'paid' && ! $amountOk) {
                // المال قد يكون قُبض فعلًا مع مبلغ مخالف: يُعلَّم الطلب للانتباه
                // ويُنبَّه السجل — لا يُكتفى بسجل failed صامت (F-22)
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
                    // مال قُبض على طلب ملغي: يُثبت الدفع ويُعلَّم للاسترداد، ولا يُحيى الطلب
                    $order->update(['payment_status' => 'paid', 'requires_refund' => true]);
                    $note = 'دفع ناجح على طلب ملغي — يحتاج استردادًا';
                } else {
                    $order->update(['payment_status' => 'paid']);
                    if ($order->status === OrderStatus::Pending) {
                        try {
                            $this->orders->changeStatus($order, OrderStatus::Confirmed, null, "تأكيد تلقائي بعد نجاح الدفع عبر {$gateway}");
                        } catch (DeadlockException $e) {
                            // جمود قفل: يُعاد رميه ليُعيد الوسيط المحاولة، ولا يُبتلع
                            // كـ «دُفع ولم يُؤكَّد» فيفقد الحدث أثره (F12)
                            throw $e;
                        } catch (Throwable $e) {
                            // «دُفع ولم يُؤكَّد» حالة تشغيلية خطيرة: تنبيه سجل +
                            // علامة ظاهرة في شاشة الطلب بدل دفنها في الويب هوك (M19)
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
                    // حدث فشل متأخر بعد نجاح: لا رجوع من paid إلى failed
                    $log->update(['status' => 'ignored', 'error' => 'حدث فشل متأخر بعد دفع ناجح — يُتجاهل']);

                    return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
                }
                $order->update(['payment_status' => 'failed']);
            } else { // refunded
                // الاسترداد لا يلغي الطلب لكنه يوقفه عن التنفيذ الصامت (F-05):
                // علم انتباه لأي استرداد على طلب غير ملغي — وبوابة الشحن
                // ترفض شحن دفع مسبق حالته ليست paid
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

    private function outcome(array $payload): string
    {
        // معاملة معلقة (Paymob: success=false مع pending=true) ليست فشلًا
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

        // حدث Stripe بنيوي: النوع (type) هو مصدر الحالة، وليس status
        // (F10) — charge.refunded / payment_intent.succeeded / *.failed
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
     * مقارنة مبلغ الحدث بإجمالي الطلب وعملته. إن لم يحمل الحدث مبلغًا
     * يُقبل (بعض البوابات لا ترسله) — وإن حمله واختلف يُرفض.
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
            // بوابة تُعلن وجوب وجود المبلغ (F10): حدث ناجح بلا مبلغ لا يُقبل
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

        // قيم غير مفردة (مصفوفات) لا تُحوَّل لنص — تُتجاهل (F-07)
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

    /** كائن معاملة Paymob الموقّع (obj) — مصدر الحقيقة الوحيد لبوابة Paymob. */
    private function paymobObj(array $payload): ?array
    {
        $obj = $payload['obj'] ?? null;

        return is_array($obj) ? $obj : null;
    }

    /**
     * حالة حدث Paymob من الحقول الموقّعة فقط (C1): علم الاسترداد/الإلغاء
     * يُفحص قبل success لأن Paymob يرسل success=true مع is_refunded/is_voided
     * فيُصنّف الاسترداد خطأً كدفع ناجح إن فُحص success أولًا.
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

    /** معرّف حدث Paymob: obj.id الموقّع (لا event_id أعلى المستوى غير الموقّع). */
    private function paymobEventId(array $payload): ?string
    {
        $obj = $this->paymobObj($payload);
        $id = $obj['id'] ?? null;

        return is_scalar($id) && $id !== '' ? mb_substr((string) $id, 0, 191) : null;
    }

    /** معرّف طلب Paymob الموقّع (obj.order.id) لربط الطلب. */
    private function paymobGatewayOrderId(array $payload): ?string
    {
        $obj = $this->paymobObj($payload);
        $id = is_array($obj['order'] ?? null) ? ($obj['order']['id'] ?? null) : null;

        return is_scalar($id) && $id !== '' ? mb_substr((string) $id, 0, 191) : null;
    }

    /**
     * مطابقة المبلغ والعملة لـ Paymob من obj.amount_cents وobj.currency فقط
     * (C1) — لا من amount/currency أعلى المستوى غير الموقّعين.
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
            // تنقية السر قبل التخزين (H1): Moyasar يضع secret_token في الجسم
            'payload' => WebhookLog::sanitizePayload($payload),
            'status' => 'received',
            'ip_address' => $ip,
        ]);
    }

    /** سجل مختصر للطلبات المرفوضة قبل التحقق — بلا تخزين للحمولة (M4). */
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
