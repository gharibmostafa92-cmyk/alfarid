<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SupplierUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupplierAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }

        if (! $user instanceof SupplierUser || ! $user->is_active || $user->supplier_id === null
            || ! $user->supplier()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كمستخدم مورد أو الحساب غير نشط.',
            ], 403);
        }

        Auth::guard('supplier')->setUser($user);
        Auth::shouldUse('supplier');

        return $next($request);
    }
}
