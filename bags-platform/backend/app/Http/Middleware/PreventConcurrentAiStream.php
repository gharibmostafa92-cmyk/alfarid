<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * M8: an SSE stream holds a PHP-FPM worker until the model responds. Cap one
 * concurrent stream per staff member so a few users cannot exhaust the pool.
 */
class PreventConcurrentAiStream
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('staff');
        if ($user === null) {
            return $next($request);
        }

        // L5: the cap must cover the longest possible stream (PHP execution
        // ceiling plus margin), or the lock lapses mid-stream and a second
        // concurrent stream slips through.
        $maxExecution = (int) ini_get('max_execution_time');
        $ttl = (int) config('ai.stream_lock_ttl_seconds', $maxExecution > 0 ? $maxExecution + 120 : 600);
        $lock = Cache::lock('ai-stream:'.$user->id, max($ttl, 60));
        if (! $lock->get()) {
            return response()->json(['message' => 'يوجد بث ذكاء اصطناعي قيد التنفيذ بالفعل؛ انتظر انتهاءه.'], 429)
                ->header('Retry-After', (string) max($ttl, 60));
        }
        $request->attributes->set('ai_stream_lock', $lock);

        try {
            return $next($request);
        } catch (Throwable $exception) {
            $lock->release();

            throw $exception;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $lock = $request->attributes->get('ai_stream_lock');
        if ($lock instanceof Lock) {
            $lock->release();
        }
    }
}
