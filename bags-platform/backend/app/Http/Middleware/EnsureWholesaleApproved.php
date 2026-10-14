<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureWholesaleApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('customer');
        $user = $request->user('customer');

        if (
            ! $user instanceof Customer
            || ! in_array($user->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
            || $user->status !== CustomerStatus::Approved
        ) {
            return response()->json([
                'message' => 'هذا القسم مخصص لتجار الجملة المعتمدين فقط.',
            ], 403);
        }

        return $next($request);
    }
}
