<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\Auth\LoginRequest;
use App\Http\Resources\Admin\SupplierUserResource;
use App\Models\SupplierUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $user = SupplierUser::query()->where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
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
            'token' => $user->createToken('supplier-token')->plainTextToken,
            'user' => SupplierUserResource::make($user),
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'company_name' => $supplier->company_name,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }
}
