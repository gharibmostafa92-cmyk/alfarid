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
    public function issue(User $user, ?string $credentials = null, array $abilities = ['*']): string
    {
        return DB::transaction(function () use ($user, $credentials, $abilities): string {
            // Pessimistic row lock serializes simultaneous logins and prevents token race conditions.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active || $user->is_system) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            // Constant-time check prevents timing attacks against handoff signature verification.
            if ($credentials !== null && ! hash_equals(StaffHandoffService::credentialFingerprint($user), $credentials)) {
                throw new BusinessException('رابط الدخول غير صالح أو انتهت صلاحيته.', [], 401);
            }

            // Prune expired tokens and enforce FIFO token cap to prevent unbounded session table growth.
            self::pruneAndCap($user, (int) config('auth.staff_max_tokens', 20));

            // L8: abilities follow the roles committed before this lock, not a
            // destination computed on the unlocked row.
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->load(['branch', 'roles.permissions', 'permissions']);
            if ($user->roles->isNotEmpty()) {
                $destination = app(LoginDestinationResolver::class)->resolve($user);
                $abilities = ($destination['destination'] ?? null) === 'pos' ? ['pos'] : ['*'];
            }
            $abilities = $abilities === [] ? ['*'] : array_values(array_unique($abilities));

            return $user->createToken('staff-token', $abilities, now()->addMinutes(max(1, (int) config('auth.staff_token_minutes', 1440))))->plainTextToken;
        });
    }

    /**
     * Prune expired personal access tokens and enforce a FIFO session cap for the given tokenable model.
     */
    public static function pruneAndCap(object $tokenable, int $maxTokens): void
    {
        $expiry = config('sanctum.expiration');
        $tokenable->tokens()->where(fn ($query) => $query->where('expires_at', '<=', now())
            ->when($expiry, fn ($query) => $query->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();

        $limit = max(1, $maxTokens);
        $old = $tokenable->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
        if ($old->isNotEmpty()) {
            $tokenable->tokens()->whereIn('id', $old)->delete();
        }
    }
}
