<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookLog;
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

    /** @return array{http: int, body: array<string, mixed>} */
    public function handle(string $gateway, array $payload, string $rawBody, ?string $signature, ?string $ip): array
    {
        $provider = 'payment:'.$gateway;
        // مفتاح الحدث: معرّف البوابة، وإلا بصمة جسم الطلب (H4)
        $eventId = $this->extractEventId($payload) ?? 'sha256:'.hash('sha256', $rawBody);

        // حد حجم الجسم أولًا — الرفض بسجل مختصر بلا حمولة (M4)
        if (strlen($rawBody) > 262144) {
            $this->slimLog($provider, $eventId, 'rejected', 'payload too large', $ip);

            return ['http' => 413, 'body' => ['received' => false, 'message' => 'payload too large']];
        }

        $config = config("webhooks.payment.{$gateway}");
        if (! is_array($config)) {
            $this->slimLog($provider, $eventId, 'failed', 'بوابة دفع غير معروفة', $ip);

            return ['http' => 404, 'body' => ['received' => false, 'message' => 'unknown gateway']];
        }

        // التحقق من التوقيع قبل تخزين الحمولة: محاولات مجهولة لا تملأ
        // webhook_logs.payload — تُسجل مختصرة فقط (M4)
        if (! $this->verifier->verify($config['secret'] ?? null, $rawBody, $signature)) {
            $this->slimLog($provider, $eventId, 'invalid_signature', null, $ip);

            return ['http' => 403, 'body' => ['received' => false, 'message' => 'invalid signature']];
        }

        $log = $this->startLog($provider, $eventId, $payload, $ip);
        $log->update(['signature_valid' => true]);

        return DB::transaction(function () use ($gateway, $provider, $eventId, $payload, $log, $config): array {
            // قفل استشاري على مفتاح الحدث: نسختان متزامنتان من نفس الحدث تتسلسلان هنا
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$provider.':'.$eventId]);

            if ($this->alreadyProcessed($provider, $log)) {
                $log->update(['status' => 'duplicate']);

                return ['http' => 200, 'body' => ['received' => true, 'duplicate' => true]];
            }

            $orderNumber = $this->extractOrderNumber($payload);
            $order = $orderNumber !== null
                ? Order::query()->where('order_number', $orderNumber)->lockForUpdate()->first()
                : null;
            if ($order === null) {
                $log->update(['status' => 'failed', 'error' => 'طلب غير موجود: '.($orderNumber ?? '—')]);

                return ['http' => 200, 'body' => ['received' => false, 'message' => 'order not found']];
            }

            $outcome = $this->outcome($payload);
            if ($outcome === 'ignored' || $outcome === 'pending') {
                $log->update([
                    'status' => 'ignored',
                    'error' => $outcome === 'pending' ? 'معاملة معلقة لدى البوابة (pending)' : 'حالة دفع غير معروفة في الحمولة',
                ]);

                return ['http' => 200, 'body' => ['received' => true, 'ignored' => true]];
            }

            // التحقق من المبلغ والعملة قبل أي تغيير (H5)
            if ($outcome === 'paid' && ! $this->amountMatches($payload, $order, $config)) {
                $log->update(['status' => 'failed', 'error' => 'amount mismatch']);

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
                $order->update(['payment_status' => 'refunded']);
                if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                    $note = 'استرداد على طلب مشحون/مُسلَّم — يحتاج مراجعة';
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

    /**
     * مقارنة مبلغ الحدث بإجمالي الطلب وعملته. إن لم يحمل الحدث مبلغًا
     * يُقبل (بعض البوابات لا ترسله) — وإن حمله واختلف يُرفض.
     *
     * @param  array<string, mixed>  $config
     */
    private function amountMatches(array $payload, Order $order, array $config): bool
    {
        $currency = $this->firstValue($payload, ['currency', 'data.currency', 'obj.currency', 'obj.order.currency']);
        if ($currency !== null && strtoupper((string) $currency) !== 'EGP') {
            return false;
        }

        $amount = $this->firstValue($payload, ['amount', 'data.amount', 'obj.amount', 'obj.order.amount']);
        $divisor = (float) ($config['amount_divisor'] ?? 1);
        if ($amount === null) {
            $cents = $this->firstValue($payload, ['amount_cents', 'data.amount_cents', 'obj.amount_cents', 'obj.order.amount_cents']);
            if ($cents !== null) {
                $amount = $cents;
                $divisor = 100.0;
            }
        }
        if ($amount === null) {
            return true;
        }

        return abs(round(((float) $amount) / $divisor, 2) - (float) $order->grand_total) < 0.01;
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
        $value = $this->firstValue($payload, ['event_id', 'transaction_id', 'obj.id', 'data.id', 'data.transaction_id']);

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
