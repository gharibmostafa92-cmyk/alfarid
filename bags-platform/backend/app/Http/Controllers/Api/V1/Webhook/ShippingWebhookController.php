<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Orders\ShippingWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shipping carrier webhooks — bypasses Sanctum auth; relies on carrier-specific
 * HMAC signature verification and idempotency logging in webhook_logs.
 */
class ShippingWebhookController extends Controller
{
    public function __construct(private readonly ShippingWebhookService $webhooks) {}

    /**
     * Process carrier tracking status updates and delivery confirmations.
     */
    public function handle(Request $request, string $company): JsonResponse
    {
        $header = (string) (config('webhooks.shipping.signature_header') ?? 'X-Webhook-Signature');
        $raw = $request->getContent();

        // Enforce 256KB ceiling prior to JSON decoding to guard against DoS; oversize payloads are logged and rejected.
        $payload = strlen($raw) > 262144 ? null : json_decode($raw, true);
        $result = $this->webhooks->handle(
            $company,
            is_array($payload) ? $payload : [],
            $raw,
            $request->header($header),
            $request->ip(),
        );

        return response()->json($result['body'], $result['http']);
    }
}
