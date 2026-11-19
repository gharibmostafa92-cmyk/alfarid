<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\SupplierUser;
use App\Rules\PasswordByteLimit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class ChangePortalPasswordController
{
    public function __invoke(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof Customer || $actor instanceof SupplierUser, 403);
        $token = $actor->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 401);

        if (array_diff(array_keys($request->all()), ['current_password', 'password', 'password_confirmation']) !== []) {
            throw ValidationException::withMessages(['password' => 'بيانات الطلب غير صالحة.']);
        }

        $minimum = $actor instanceof Customer && $actor->isCommercialTier() ? 12 : 8;
        $validated = $request->validate([
            'current_password' => ['bail', 'required', 'string', 'max:255'],
            'password' => ['bail', 'required', 'string', "min:$minimum", new PasswordByteLimit, 'different:current_password', 'confirmed'],
            'password_confirmation' => ['bail', 'required', 'string', 'max:72'],
        ]);

        $original = $actor->getAuthPassword();
        $current = $validated['current_password'];
        $password = $validated['password'];
        if (! is_string($original) || $original === '' || str_contains($current, "\0") || ! Hash::check($current, $original)) {
            throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
        }
        if (str_contains($password, "\0") || (strlen($current) > 72 && substr($current, 0, 72) === $password)) {
            throw ValidationException::withMessages(['password' => 'اختر كلمة مرور جديدة صالحة ومختلفة.']);
        }
        $replacement = Hash::make($password);

        DB::transaction(function () use ($actor, $token, $original, $replacement): void {
            $locked = $actor->newQuery()->lockForUpdate()->find($actor->getKey());
            abort_if($locked === null, 401);
            abort_unless(hash_equals($original, (string) $locked->getAuthPassword()), 409);
            abort_unless($locked->tokens()->whereKey($token->getKey())->exists(), 401);
            abort_if($token->expires_at?->isPast(), 401);

            if ($locked instanceof Customer) {
                abort_if($locked->status === CustomerStatus::Suspended, 403);
                $locked->setRememberToken(Str::random(60));
            } else {
                abort_unless($locked->is_active && $locked->supplier()->where('is_active', true)->exists(), 403);
            }

            $locked->forceFill(['password' => $replacement])->save();
            $locked->tokens()->whereKeyNot($token->getKey())->delete();

            if ($locked instanceof Customer) {
                DB::table('password_reset_tokens')->where('email', $locked->getEmailForPasswordReset())->delete();
                CustomerAuditLog::record($locked, 'password_changed', null);
            }
        });

        return response()->noContent(204, ['Cache-Control' => 'no-store']);
    }
}
