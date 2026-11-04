<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConcurrentRequestBarrier
{
    public function handle(Request $request, Closure $next): Response
    {
        (app('audit.concurrent.barrier'))();

        return $next($request);
    }
}
