<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ويب هوك بوابات الدفع — بلا مصادقة Sanctum؛ الحماية بالتوقيع
 * الرقمي HMAC والتسجيل المسبق في webhook_logs (Idempotency).
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhooks) {}

    public function handle(Request $request, string $gateway): JsonResponse
    {
        $header = (string) (config("webhooks.payment.{$gateway}.signature_header") ?? 'X-Webhook-Signature');
        $payload = json_decode($request->getContent(), true);
        $result = $this->webhooks->handle(
            $gateway,
            is_array($payload) ? $payload : [],
            $request->getContent(),
            $request->header($header),
            $request->ip(),
        );

        return response()->json($result['body'], $result['http']);
    }
}
