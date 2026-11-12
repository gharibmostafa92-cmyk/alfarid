<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Customer\CustomerStatus;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * L4: the unified me/logout routes sit behind auth:sanctum only, so a
 * disabled staff account or a suspended customer/supplier keeps reading its
 * profile until the token itself expires. Reject inactive identities here,
 * mirroring the realm guards, without touching legitimate self-service.
 */
final class EnsureUnifiedAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve through the sanctum guard: the default (web) guard has no
        // user on stateless API requests, which would neuter this check.
        $user = $request->user('sanctum') ?? $request->user();
        $blocked = match (true) {
            $user instanceof User => ! $user->is_active || $user->is_system,
            $user instanceof Customer => $user->status === CustomerStatus::Suspended,
            $user instanceof SupplierUser => ! $user->is_active,
            default => $user !== null,
        };

        if ($blocked) {
            return response()->json(['message' => 'تم إيقاف الحساب.'], 403);
        }

        return $next($request);
    }
}
