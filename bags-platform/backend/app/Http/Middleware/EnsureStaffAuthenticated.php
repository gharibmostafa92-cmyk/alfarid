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

        // Temporary passwords must be rotated upon first login; restrict access exclusively
        // to own profile viewing, password updates, and logout until completed.
        // task3 H1: the rotation endpoints must also survive the ability check
        // below, otherwise a ['pos'] token can never rotate and the account is
        // locked out. Scoped to profile view + password rotation only — logout
        // stays forbidden for pos tokens (committed least-privilege contract).
        $isRotationRequest = $this->isPasswordRotationRequest($request, $user);
        if ((bool) $user->must_change_password && ! $isRotationRequest) {
            return response()->json([
                'message' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
                'code' => 'password_change_required',
            ], 403);
        }

        $token = $user->currentAccessToken();
        $posReachable = $request->is('api/v1/pos/*') || $this->isBranchLockExempt($request);
        if ($token !== null && ! $token->can('*')
            && ! ($posReachable && $token->can('pos'))) {
            return response()->json(['message' => 'التوكن غير مصرح له بهذه الواجهة.'], 403);
        }

        // Staff assigned to a deactivated branch are fail-closed everywhere
        // except own profile/password rotation and logout, mirroring BranchAccess.
        if (! $this->isBranchLockExempt($request)
            && ! $request->is('api/v1/admin/auth/logout') && ! $request->is('api/v1/auth/logout')
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

    private function isPasswordRotationRequest(Request $request, User $user): bool
    {
        // Temporary-password rotation is scoped to own profile views, own
        // password rotation, and logout. admin.users.update self-access is
        // intentionally NOT exempt here; use me/password instead (M1).
        if ($request->isMethod('GET') && $request->is('api/v1/admin/me')) {
            return true;
        }
        if (in_array($request->method(), ['PUT', 'PATCH'], true) && $request->is('api/v1/admin/me/password')) {
            return true;
        }

        return $request->is('api/v1/admin/auth/logout') || $request->is('api/v1/auth/logout');
    }

    private function isBranchLockExempt(Request $request): bool
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
