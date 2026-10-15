<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ForgotPasswordRequest;
use App\Http\Requests\Store\LoginRequest;
use App\Http\Requests\Store\RegisterRequest;
use App\Http\Resources\Store\CustomerResource;
use App\Services\CustomerAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** مصادقة المتجر القطاعي: تسجيل فوري معتمد، دخول، خروج، واستعادة كلمة المرور. */
class AuthController extends Controller
{
    public function __construct(private readonly CustomerAuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $customer = $this->auth->registerRetail($request->validated());
        $token = $this->auth->issueToken($customer, 'store-token');

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم إنشاء حسابك بنجاح', 'token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): CustomerResource
    {
        $customer = $this->auth->login($request->validated()['login'], $request->validated()['password']);
        $token = $this->auth->issueToken($customer, 'store-token');

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم تسجيل الدخول بنجاح', 'token' => $token]);
    }

    /** خروج: حذف التوكن الحالي فقط (بقية الجلسات على أجهزة أخرى تظل فعالة). */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword($request->validated()['email'] ?? null, $request->validated()['phone'] ?? null);

        return response()->json(['message' => 'لو الحساب موجود، أرسلنا رمز الاستعادة إليه.']);
    }
}
