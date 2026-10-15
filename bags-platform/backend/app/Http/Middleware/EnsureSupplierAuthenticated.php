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
        Auth::shouldUse('supplier');
        $user = $request->user('supplier');

        if ($request->user('sanctum') === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }

        if (! $user instanceof SupplierUser || ! $user->is_active || $user->supplier_id === null) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كمستخدم مورد أو الحساب غير نشط.',
            ], 403);
        }

        return $next($request);
    }
}
