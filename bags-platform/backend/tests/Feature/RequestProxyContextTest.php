<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequestProxyContextTest extends TestCase
{
    public function test_request_log_context_uses_the_resolved_client_address(): void
    {
        // Start without a trusted-proxy state left by an earlier request.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        TrustProxies::at(['172.31.0.10']);
        $context = null;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context): void {
            if ($event->message === 'proxy-context-probe') {
                $context = $event->context;
            }
        });
        Route::get('/proxy-context-probe', function (Request $request) {
            Log::info('proxy-context-probe');

            return response()->json(['ip' => $request->ip()]);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '172.31.0.10'])
            ->get('/proxy-context-probe', ['X-Forwarded-For' => '198.51.100.42'])
            ->assertOk()->assertJsonPath('ip', '198.51.100.42');
        $this->assertSame('198.51.100.42', $context['client_ip'] ?? null);
    }
}
