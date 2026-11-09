<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\LoginRequest;
use App\Http\Resources\Admin\Procurement\SupplierUserResource;
use App\Models\SupplierUser;
use App\Services\Auth\StaffTokenService;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Supplier portal authentication: verifies staff and company active status, issuing scoped Sanctum tokens.
 */
class AuthController extends Controller
{
    /**
     * Authenticate supplier staff credentials and issue portal API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = mb_strtolower(trim($request->validated('email')));
        $user = SupplierUser::query()->whereRaw('lower(btrim(email)) = ?', [$email])->first();
        $passwordMatches = Hash::check($request->validated('password'), $user?->password ?? CustomerAuthService::dummyHash());

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

    /**
     * Prunes expired tokens, enforces FIFO token cap under row lock, and issues a new Sanctum token.
     *
     * @param  SupplierUser  $user  Target authenticated supplier user
     * @return string Plain-text bearer token
     */
    private function issueToken(SupplierUser $user): string
    {
        return DB::transaction(function () use ($user): string {
            $user = SupplierUser::query()->lockForUpdate()->findOrFail($user->id);
            StaffTokenService::pruneAndCap($user, (int) config('auth.supplier_max_tokens', 5));

            return $user->createToken('supplier-token')->plainTextToken;
        });
    }

    /**
     * Revoke the current supplier staff API token.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
