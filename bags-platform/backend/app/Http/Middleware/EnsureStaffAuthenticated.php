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

        if (! $user instanceof User || ! $user->is_active || $user->is_system) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
            ], 403);
        }

        // L8: كلمة المرور المؤقتة يجب تدويرها في أول دخول — تُسمح فقط
        // مشاهدة الحساب وتغيير كلمة السر الذاتي وتسجيل الخروج حتى التدوير.
        if ((bool) $user->must_change_password && ! $this->isPasswordRotationRequest($request, $user)) {
            return response()->json([
                'message' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
                'code' => 'password_change_required',
            ], 403);
        }

        $token = $user->currentAccessToken();
        if ($token !== null && ! $token->can('*')
            && ! ($request->is('api/v1/pos/*') && $token->can('pos'))) {
            return response()->json(['message' => 'التوكن غير مصرح له بهذه الواجهة.'], 403);
        }

        return $next($request);
    }

    private function isPasswordRotationRequest(Request $request, User $user): bool
    {
        // Read-only self views, own password rotation, and logout stay usable.
        if ($request->isMethod('GET') && $request->is('api/v1/admin/me')) {
            return true;
        }
        if (in_array($request->method(), ['PUT', 'PATCH'], true) && $request->is('api/v1/admin/me/password')) {
            return true;
        }
        if ($request->is('api/v1/admin/auth/logout') || $request->is('api/v1/auth/logout')) {
            return true;
        }
        $routeUser = $request->route('user');
        $targetId = $routeUser instanceof User ? $routeUser->getKey() : $routeUser;

        return in_array($request->method(), ['PUT', 'PATCH'], true)
            && $request->routeIs('admin.users.update')
            && (int) $targetId === (int) $user->getKey();
    }
}
