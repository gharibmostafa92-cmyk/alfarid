<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\ShippingWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ويب هوك شركات الشحن — بلا مصادقة Sanctum؛ الحماية بالتوقيع
 * الرقمي HMAC بسر الشركة والتسجيل المسبق في webhook_logs.
 */
class ShippingWebhookController extends Controller
{
    public function __construct(private readonly ShippingWebhookService $webhooks) {}

    public function handle(Request $request, string $company): JsonResponse
    {
        $header = (string) (config('webhooks.shipping.signature_header') ?? 'X-Webhook-Signature');
        $payload = json_decode($request->getContent(), true);
        $result = $this->webhooks->handle(
            $company,
            is_array($payload) ? $payload : [],
            $request->getContent(),
            $request->header($header),
            $request->ip(),
        );

        return response()->json($result['body'], $result['http']);
    }
}
