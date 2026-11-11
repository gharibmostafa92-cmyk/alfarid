<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Http\Middleware\PreventConcurrentAiStream;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** task2 M8: one concurrent SSE stream per staff member. */
class Task2AiStreamLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_second_concurrent_stream_is_rejected_and_lock_is_released(): void
    {
        $user = new User;
        $user->id = 42;
        $middleware = new PreventConcurrentAiStream;
        $next = fn () => response('ok');

        $first = Request::create('/api/v1/admin/ai/conversations/1/stream', 'POST');
        $first->setUserResolver(fn () => $user);
        $this->assertSame(200, $middleware->handle($first, $next)->getStatusCode());

        $second = Request::create('/api/v1/admin/ai/conversations/1/stream', 'POST');
        $second->setUserResolver(fn () => $user);
        $this->assertSame(429, $middleware->handle($second, $next)->getStatusCode());

        // Terminable middleware releases the slot after the first stream ends.
        $middleware->terminate($first, response('ok'));
        $this->assertTrue(Cache::lock('ai-stream:42', 60)->get());
    }

    public function test_lock_is_released_when_the_stream_throws(): void
    {
        $user = new User;
        $user->id = 43;
        $middleware = new PreventConcurrentAiStream;
        $request = Request::create('/api/v1/admin/ai/conversations/1/stream', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            $middleware->handle($request, fn () => throw new \RuntimeException('boom'));
            $this->fail('The exception should bubble up.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertTrue(Cache::lock('ai-stream:43', 60)->get());
    }
}
