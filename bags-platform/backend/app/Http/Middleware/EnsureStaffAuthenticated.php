<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('staff');
        $user = $request->user('staff');

        if ($request->user('sanctum') === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
            ], 403);
        }

        return $next($request);
    }
}
