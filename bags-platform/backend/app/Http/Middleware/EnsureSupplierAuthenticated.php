<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SupplierUser;
use App\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
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

        $token = $user->currentAccessToken();
        // Bearer tokens only: a stateful session TransientToken cannot be revoked
        // by token pruning and always reports every ability as granted.
        if (! $user instanceof SupplierUser || ! $token instanceof PersonalAccessToken
            || ! $user->is_active || $user->supplier_id === null
            || ! $token->can('supplier')) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كمستخدم مورد أو الحساب غير نشط.',
            ], 403);
        }

        $supplier = Supplier::query()->whereKey($user->supplier_id)->where('is_active', true)->first();
        if ($supplier === null) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كمستخدم مورد أو الحساب غير نشط.',
            ], 403);
        }
        $user->setRelation('supplier', $supplier);

        Auth::guard('supplier')->setUser($user);
        Auth::shouldUse('supplier');

        return $next($request);
    }
}
