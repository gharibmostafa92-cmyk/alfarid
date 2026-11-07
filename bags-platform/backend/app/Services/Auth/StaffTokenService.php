<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Issues and enforces lifecycle boundaries for staff API authentication tokens.
 */
final class StaffTokenService
{
    /**
     * Issue a personal access token for a backoffice staff user under transactional concurrency locks.
     *
     * @param  User  $user  Target staff member.
     * @param  string|null  $credentials  Optional credential fingerprint for handoff validation.
     * @return string Plain-text bearer token.
     *
     * @throws BusinessException If the user is inactive, a system account, or credentials mismatch.
     */
    public function issue(User $user, ?string $credentials = null): string
    {
        return DB::transaction(function () use ($user, $credentials): string {
            // Pessimistic row lock serializes simultaneous logins and prevents token race conditions.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active || $user->is_system) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            // Constant-time check prevents timing attacks against handoff signature verification.
            if ($credentials !== null && ! hash_equals(StaffHandoffService::credentialFingerprint($user), $credentials)) {
                throw new BusinessException('رابط الدخول غير صالح أو انتهت صلاحيته.', [], 401);
            }

            // Prune expired tokens to prevent unbounded session table growth.
            $expiry = config('sanctum.expiration');
            $user->tokens()->where(fn ($query) => $query->where('expires_at', '<=', now())
                ->when($expiry, fn ($query) => $query->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();

            // FIFO token cap terminates oldest sessions to prevent credential accumulation across devices.
            $limit = max(1, (int) config('auth.staff_max_tokens', 20));
            $old = $user->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
            if ($old->isNotEmpty()) {
                $user->tokens()->whereIn('id', $old)->delete();
            }

            return $user->createToken('staff-token', ['*'], now()->addMinutes(max(1, (int) config('auth.staff_token_minutes', 43200))))->plainTextToken;
        });
    }
}
