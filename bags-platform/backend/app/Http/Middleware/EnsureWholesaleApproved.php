<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the incoming request is authenticated as an approved wholesale merchant.
 */
class EnsureWholesaleApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('customer');
        $user = $request->user('customer');

        if ($request->user('sanctum') === null) {
            return response()->json([
                'message' => 'يرجى تسجيل الدخول أولًا.',
            ], 401);
        }

        if (
            ! $user instanceof Customer
            || ! $user->isWholesaleApproved()
        ) {
            return response()->json([
                'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
            ], 403);
        }

        return $next($request);
    }
}
