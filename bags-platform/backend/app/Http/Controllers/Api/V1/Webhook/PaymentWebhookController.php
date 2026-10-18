<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ويب هوك بوابات الدفع — بلا مصادقة Sanctum؛ الحماية بنمط توقيع
 * كل بوابة (driver) وبالتسجيل في webhook_logs (Idempotency).
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhooks) {}

    public function handle(Request $request, string $gateway): JsonResponse
    {
        $raw = $request->getContent();

        // حد الحجم قبل أي فك ترميز: حمولة ضخمة لا تُفكَّك (F9). الرفض
        // والتسجيل المختصر يتمّان في الخدمة (وقتها لا تُخزَّن الحمولة)
        $payload = strlen($raw) > 262144 ? null : json_decode($raw, true);
        // الهيدرات بمفاتيح صغيرة + الـ query: أنماط Paymob (hmac في query)
        // وStripe (هيدر موقّع) تحتاج أكثر من هيدر واحد (F-06)
        $headers = collect($request->headers->all())
            ->map(fn (array $values): ?string => $values[0] ?? null)
            ->all();
        $result = $this->webhooks->handle(
            $gateway,
            is_array($payload) ? $payload : [],
            $raw,
            $headers,
            $request->query(),
            $request->ip(),
        );

        return response()->json($result['body'], $result['http']);
    }
}
