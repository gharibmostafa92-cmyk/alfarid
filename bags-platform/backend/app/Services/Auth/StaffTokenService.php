<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StaffTokenService
{
    public function issue(User $user, ?string $credentials = null): string
    {
        return DB::transaction(function () use ($user, $credentials): string {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active || $user->is_system) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            if ($credentials !== null && ! hash_equals(StaffHandoffService::credentialFingerprint($user), $credentials)) {
                throw new BusinessException('رابط الدخول غير صالح أو انتهت صلاحيته.', [], 401);
            }
            $expiry = config('sanctum.expiration');
            $user->tokens()->where(fn ($query) => $query->where('expires_at', '<=', now())
                ->when($expiry, fn ($query) => $query->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();
            $limit = max(1, (int) config('auth.staff_max_tokens', 20));
            $old = $user->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
            if ($old->isNotEmpty()) {
                $user->tokens()->whereIn('id', $old)->delete();
            }

            return $user->createToken('staff-token', ['*'], now()->addMinutes(max(1, (int) config('auth.staff_token_minutes', 120))))->plainTextToken;
        });
    }
}
