<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Customer\CustomerStatus;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAuthenticated
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
        $canInspectRejection = $request->routeIs('wholesale.auth.application-status', 'store.auth.logout');
        // Pending traders additionally keep their self-service onboarding
        // lanes: application status inspection and document (re-)upload.
        $canPendingSelfService = $request->routeIs('wholesale.auth.application-status', 'wholesale.auth.documents.store', 'store.auth.logout');

        if (! $user instanceof Customer
            || ! $token instanceof PersonalAccessToken
            || (! $token->can('store-token') && ! $token->can('wholesale-token') && ! $token->can('store') && ! $token->can('wholesale'))
            || $user->status === CustomerStatus::Suspended
            || ($user->status === CustomerStatus::Rejected && ! $canInspectRejection)
            || ($user->status === CustomerStatus::Pending && ! $canPendingSelfService)) {
            return response()->json([
                'message' => 'غير مصرح لك بالدخول كعميل.',
            ], 403);
        }

        Auth::guard('customer')->setUser($user);
        Auth::shouldUse('customer');

        return $next($request);
    }
}
