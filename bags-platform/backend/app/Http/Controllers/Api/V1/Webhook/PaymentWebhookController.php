<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment gateway webhooks — bypasses Sanctum auth; relies on provider-specific
 * signature verification and idempotency tracking in webhook_logs.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentWebhookService $webhooks) {}

    /**
     * Ingest and verify inbound payment gateway webhook notifications.
     */
    public function handle(Request $request, string $gateway): JsonResponse
    {
        $raw = $request->getContent();

        // Enforce 256KB ceiling before JSON decoding to prevent memory exhaustion DoS;
        // oversized payloads are rejected and logged by the service without parsing.
        $payload = strlen($raw) > 262144 ? null : json_decode($raw, true);
        // Normalize headers and include query parameters for drivers verifying signatures
        // via query params (e.g. Paymob HMAC) or provider-specific headers (e.g. Stripe).
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
