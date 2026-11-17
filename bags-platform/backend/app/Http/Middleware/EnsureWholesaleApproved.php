<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the incoming request is authenticated as an approved wholesale merchant.
 */
class EnsureWholesaleApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('customer');
        $user = $request->user('customer') ?? $request->user('sanctum');

        if ($user === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }
        if ($user instanceof Customer) {
            Auth::guard('customer')->setUser($user);
        }

        if (
            ! $user instanceof Customer
            || ! $user->isWholesaleApproved()
        ) {
            return response()->json([
                'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
                'code' => 'wholesale_not_approved',
            ], 403);
        }

        // Channel-scoped tokens: a retail-scoped token must not open the
        // wholesale portal even when the account itself is approved.
        // Tokens with the '*' ability (legacy full-access) still pass.
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken
            || (! $token->can('wholesale-token') && ! $token->can('wholesale'))) {
            return response()->json([
                'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
                'code' => 'wholesale_not_approved',
            ], 403);
        }

        return $next($request);
    }
}
