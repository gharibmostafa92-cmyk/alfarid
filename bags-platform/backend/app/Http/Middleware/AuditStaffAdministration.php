<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\StaffAdministration;
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
        if (! in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)
            || ! preg_match('~^api/v1/admin/(users|roles|settings|branches|ai)(?:/([0-9a-zA-Z\-]+))?(?:/.*)?$~', $request->path(), $target)) {
            return $next($request);
        }
        $actor = $request->user('staff');
        $key = 'staff:admin-mutations:'.($actor?->id ?? 'guest');
        $limit = max(1, (int) config('auth.staff_admin_mutations_minute', 60));
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['message' => 'طلبات إدارية كثيرة؛ حاول بعد قليل.'], 429);
        }
        RateLimiter::hit($key, 60);
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
            // Runs after a controller transaction has rolled back, so a rejected action remains visible.
            $type = ['users' => 'user', 'roles' => 'role', 'settings' => 'settings', 'branches' => 'branch', 'ai' => 'ai_conversation'][$target[1]];
            try {
                $targetId = isset($target[2]) && ctype_digit($target[2]) ? (int) $target[2] : null;
                StaffAdministration::audit($actor, 'administration.denied', $type, $targetId, []);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
