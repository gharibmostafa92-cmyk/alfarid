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
     * @param  array|null  $abilities  Optional narrowing of the granted abilities; never widens the grant.
     * @return string Plain-text bearer token.
     *
     * @throws BusinessException If the user is inactive, a system account, or credentials mismatch.
     */
    public function issue(User $user, ?string $credentials = null, ?array $abilities = null): string
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
            self::pruneAndCap($user, (int) config('auth.staff_max_tokens', 20), (int) config('auth.staff_token_minutes'));

            // L8: abilities follow the roles committed before this lock, not a
            // destination computed on the unlocked row.
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            $user->load(['branch', 'roles.permissions', 'permissions']);
            // L-1: fail closed. A user without roles makes the resolver throw 403 here,
            // inside the transaction, so no token (and no orphan) is ever committed.
            $destination = app(LoginDestinationResolver::class)->resolve($user);
            $resolved = ($destination['destination'] ?? null) === 'pos' ? ['pos'] : ['*'];
            if ($abilities === null) {
                $abilities = $resolved;
            } elseif (in_array('*', $resolved, true)) {
                // Narrow-only: an explicit request is honored as stated but
                // can never exceed the resolved grant.
                $abilities = array_values(array_unique($abilities));
            } else {
                $abilities = array_values(array_intersect($abilities, $resolved));
            }
            if ($abilities === []) {
                throw new BusinessException(
                    'الصلاحيات المطلوبة غير متاحة لهذا الحساب.',
                    [],
                    403,
                    'invalid_token_abilities',
                );
            }

            return $user->createToken('staff-token', $abilities, now()->addMinutes(max(1, (int) config('auth.staff_token_minutes'))))->plainTextToken;
        });
    }

    /**
     * Prune expired personal access tokens and enforce a FIFO session cap for the given tokenable model.
     *
     * @param  int|null  $validMinutes  Token lifetime governing the created_at fallback. Each caller
     *                                  passes the lifetime it issues tokens with (staff/customer/supplier
     *                                  minutes) instead of the unrelated sanctum.expiration default, so a
     *                                  shorter global expiry can never reap live channel tokens early —
     *                                  and a longer one can never keep dead sessions prunable only by cap.
     */
    public static function pruneAndCap(object $tokenable, int $maxTokens, ?int $validMinutes = null): void
    {
        $expiry = $validMinutes ?? config('sanctum.expiration');
        $tokenable->tokens()->where(fn ($query) => $query->where('expires_at', '<=', now())
            ->when($expiry, fn ($query) => $query->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();

        $keepCount = max(0, $maxTokens - 1);
        if ($keepCount === 0) {
            $tokenable->tokens()->delete();

            return;
        }

        $keepSubquery = $tokenable->tokens()
            ->select('id')
            ->orderByDesc('id')
            ->limit($keepCount);

        $tokenable->tokens()
            ->whereNotIn('id', $keepSubquery)
            ->delete();
    }
}
