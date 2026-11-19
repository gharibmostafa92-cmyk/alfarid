<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventLoginWithActiveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() !== null && $request->user('sanctum') !== null) {
            return response()->json([
                'code' => 'active_session',
                'message' => 'لديك جلسة مفتوحة بالفعل. سجّل الخروج قبل تسجيل دخول آخر.',
            ], 409);
        }

        return $next($request);
    }
}
