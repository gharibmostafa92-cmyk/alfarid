<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\StaffRole;
use App\Models\Branch;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces staff authentication, account activation, password rotation, token abilities, and branch active state.
 */
class EnsureStaffAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // M-3: resolve the bearer token once (sanctum guard) and share it with the staff guard,
        // instead of a second token lookup + user load through an independent RequestGuard.
        $user = $request->user('sanctum');
        if ($user === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }

        if (! $user instanceof User || ! $user->is_active || $user->is_system) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كموظف أو الحساب غير نشط.',
            ], 403);
        }

        Auth::guard('staff')->setUser($user);
        Auth::shouldUse('staff');

        // Temporary passwords must be rotated upon first login; restrict access exclusively
        // to own profile viewing, password updates, and logout until completed.
        $isLogout = $request->isMethod('POST')
            && $request->is('api/v1/admin/auth/logout', 'api/v1/auth/logout');
        $isAccountMaintenance = $isLogout || $this->isPasswordRotationRequest($request);
        if ((bool) $user->must_change_password && ! $isAccountMaintenance) {
            return response()->json([
                'message' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
                'code' => 'password_change_required',
            ], 403);
        }

        $token = $user->currentAccessToken();
        $posReachable = $request->is('api/v1/pos/*') || $isAccountMaintenance;
        if ($token !== null && ! $token->can('*')
            && ! ($posReachable && $token->can('pos'))) {
            return response()->json(['message' => 'التوكن غير مصرح له بهذه الواجهة.'], 403);
        }

        // Staff assigned to a deactivated branch are fail-closed everywhere
        // except own profile/password rotation and logout, mirroring BranchAccess.
        if (! $isAccountMaintenance
            && ! $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
            && ! $user->can('inventory.all_branches')
            && $user->branch_id !== null
            && ! $this->branchIsActive((int) $user->branch_id)) {
            return response()->json(['message' => 'الفرع المرتبط بحسابك متوقف حالياً.'], 403);
        }

        return $next($request);
    }

    /** Cache branch active status for 30 seconds to balance check frequency with deactivation propagation delay. */
    private function branchIsActive(int $branchId): bool
    {
        return (bool) Cache::remember('branch-active:'.$branchId, 30, fn () => (bool) Branch::query()->whereKey($branchId)->value('is_active'));
    }

    private function isPasswordRotationRequest(Request $request): bool
    {
        if ($request->isMethod('GET') && $request->is('api/v1/admin/me')) {
            return true;
        }
        if (in_array($request->method(), ['PUT', 'PATCH'], true) && $request->is('api/v1/admin/me/password')) {
            return true;
        }

        return false;
    }
}
