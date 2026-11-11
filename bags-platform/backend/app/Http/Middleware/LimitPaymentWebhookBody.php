<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Shared\PaymentWebhookService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitPaymentWebhookBody
{
    public function handle(Request $request, Closure $next): Response
    {
        // M2: every unauthenticated webhook path (payment, shipping, whatsapp
        // inbound/customers) is capped before Laravel decodes unbounded JSON.
        if ($request->isMethod('POST')
            && $request->is('api/v1/webhooks/*')
            && ((int) $request->headers->get('Content-Length') > PaymentWebhookService::MAX_BODY_BYTES
                || strlen($request->getContent()) > PaymentWebhookService::MAX_BODY_BYTES)) {
            return response()->json(['received' => false, 'message' => 'payload too large'], 413);
        }

        return $next($request);
    }
}
