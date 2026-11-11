<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Http\Middleware\PreventConcurrentAiStream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** L5: a concurrent AI stream is rejected with a Retry-After budget, not an empty 429. */
class AiStreamLockTest extends TestCase
{
    public function test_concurrent_stream_returns_retry_after(): void
    {
        $user = new User;
        $user->id = 97531;
        Cache::lock('ai-stream:97531', 600)->acquire();

        $request = Request::create('/api/v1/ai/chat', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));

        $response = (new PreventConcurrentAiStream)->handle($request, fn () => response('ok'));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertNotSame('', (string) $response->getContent());
    }
}
