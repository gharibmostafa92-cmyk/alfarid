<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\NetworkIdentity;
use App\Services\Auth\StaffAdministration;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AuditStaffAdministration
{
    public function handle(Request $request, Closure $next): mixed
    {
        $path = $request->path();
        $adminPrefix = 'api/v1/admin/';
        $isWrite = in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true);
        if ((! $isWrite && $request->method() !== 'GET')
            || ! str_starts_with($path, $adminPrefix)) {
            return $next($request);
        }

        $resourcePath = substr($path, strlen($adminPrefix));
        // Logout has no administrative mutation and stays out of both the
        // throttle and the denial audit.
        if ($resourcePath === 'auth/logout') {
            return $next($request);
        }
        // AI conversations carry their own throttle, but their 403s must
        // still be recorded: a snooping attempt on another agent's thread is
        // a security event, not chat noise.
        $skipThrottle = ! $isWrite
            || $resourcePath === 'ai/conversations'
            || str_starts_with($resourcePath, 'ai/conversations/');
        $segments = explode('/', $resourcePath);
        $target = [$path, $segments[0] ?? 'admin_resource', $segments[1] ?? null];

        $actor = $request->user('staff');
        // Never share one global bucket across all unauthenticated callers:
        // key guests by subnet so one network cannot throttle the world.
        $key = 'staff:admin-mutations:'.($actor?->id ?? 'guest:'.NetworkIdentity::key($request));
        $limit = max(1, (int) config('auth.staff_admin_mutations_minute', 60));
        // L-2: hit-then-compare is atomic; check-then-hit let concurrent requests overshoot.
        if (! $skipThrottle && RateLimiter::hit($key, 60) > $limit) {
            return response()->json(['message' => 'طلبات إدارية كثيرة؛ حاول بعد قليل.'], 429);
        }
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            if ($exception instanceof AuthorizationException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403)) {
                $this->record($request, $target);
            }
            throw $exception;
        }
        if ($response->getStatusCode() === 403) {
            $this->record($request, $target);
        }

        return $response;
    }

    private function record(Request $request, array $target): void
    {
        $actor = $request->user('staff');
        if ($actor instanceof User && ! $actor->is_system) {
            $auditKey = 'staff:admin-denial-audit:'.$actor->id;
            $auditLimit = max(1, (int) config('auth.staff_admin_denial_audit_minute', 60));
            if (RateLimiter::hit($auditKey, 60) > $auditLimit) {
                return;
            }

            // Runs after a controller transaction has rolled back, so a rejected action remains visible.
            $type = [
                'users' => 'user',
                'roles' => 'role',
                'settings' => 'settings',
                'branches' => 'branch',
                'ai' => 'ai_administration',
                'customers' => 'customer',
                'suppliers' => 'supplier',
                'treasuries' => 'treasury',
                'treasury-transfers' => 'treasury_transfer',
                'expenses' => 'expense',
                'supplier-payments' => 'supplier_payment',
                'supplier-invoices' => 'supplier_invoice',
                'wallet-adjustments' => 'wallet_adjustment',
                'price-lists' => 'price_list',
                'coupons' => 'coupon',
                'promotions' => 'promotion',
                'shifts' => 'shift',
                'inventory' => 'inventory',
                'stocktakes' => 'stocktake',
                'transfers' => 'stock_transfer',
            ][$target[1]] ?? 'admin_resource';
            try {
                $targetId = isset($target[2]) && ctype_digit($target[2]) ? (int) $target[2] : null;
                // Persist the full request path: nested resources
                // (branches.locations, customers.documents, ai threads) keep
                // only a segment here, so the path is what makes a denial
                // attributable in forensic review.
                StaffAdministration::audit($actor, 'administration.denied', $type, $targetId, [], [], [], ['path' => $target[0]]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
