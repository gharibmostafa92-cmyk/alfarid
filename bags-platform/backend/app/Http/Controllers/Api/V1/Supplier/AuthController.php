<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\Auth\LoginRequest;
use App\Http\Resources\Admin\SupplierUserResource;
use App\Models\SupplierUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * دخول بوابة المورد: إيميل/كلمة مرور من supplier_users، بشرط حساب نشط
 * ومورد نشط — ثم توكن Sanctum باسم supplier-token.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim($request->validated('email')));
        $user = SupplierUser::query()->whereRaw('lower(btrim(email)) = ?', [$email])->first();
        $passwordMatches = Hash::check($request->validated('password'), $user?->password ?? self::dummyHash());

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['حساب المورد هذا غير نشط؛ تواصل مع الإدارة.'],
            ]);
        }
        $supplier = $user->supplier;
        if ($supplier === null || ! $supplier->is_active) {
            throw ValidationException::withMessages([
                'email' => ['المورد التابع له هذا الحساب غير نشط حاليًا.'],
            ]);
        }

        return response()->json([
            'token' => $this->issueToken($user),
            'user' => SupplierUserResource::make($user),
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'company_name' => $supplier->company_name,
            ],
        ]);
    }

    private static function dummyHash(): string
    {
        static $hash;

        return $hash ??= Hash::make('not-a-supplier-password');
    }

    private function issueToken(SupplierUser $user): string
    {
        return DB::transaction(function () use ($user): string {
            $user = SupplierUser::query()->lockForUpdate()->findOrFail($user->id);
            $expiry = config('sanctum.expiration');
            $user->tokens()->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->when($expiry, fn ($q) => $q->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();
            $limit = max(1, (int) config('auth.supplier_max_tokens', 5));
            $old = $user->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
            if ($old->isNotEmpty()) {
                $user->tokens()->whereIn('id', $old)->delete();
            }

            return $user->createToken('supplier-token')->plainTextToken;
        });
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
