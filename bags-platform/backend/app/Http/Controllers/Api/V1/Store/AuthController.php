<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ForgotPasswordRequest;
use App\Http\Requests\Store\LoginRequest;
use App\Http\Requests\Store\PhoneVerificationRequest;
use App\Http\Requests\Store\RegisterRequest;
use App\Http\Requests\Store\ResetPasswordRequest;
use App\Http\Resources\Store\CustomerResource;
use App\Jobs\SendCustomerPhoneVerification;
use App\Services\Auth\NetworkIdentity;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use App\Services\CustomerPhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** مصادقة المتجر القطاعي: تسجيل فوري معتمد، دخول، خروج، واستعادة كلمة المرور. */
class AuthController extends Controller
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly CartService $carts,
        private readonly CustomerPhoneVerificationService $phoneVerification,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        [$customer, $token] = DB::transaction(function () use ($request): array {
            $customer = $this->auth->registerRetail($request->validated());

            return [$customer, $this->auth->issueToken($customer, 'store-token')];
        });

        // حساب جديد = دخول فوري: تُدمج سلة الزائر إن وُجدت
        $cartMerged = true;
        try {
            $this->carts->mergeGuestCart($customer, $request->header('X-Cart-Session'));
        } catch (\Throwable $exception) {
            report($exception);
            $cartMerged = false;
        }

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم إنشاء حسابك بنجاح', 'token' => $token, 'cart_merged' => $cartMerged])
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): CustomerResource
    {
        $customer = $this->auth->login($request->validated()['login'], $request->validated()['password']);
        $token = $this->auth->issueToken($customer, 'store-token');

        // دمج سلة الزائر (إن وُجدت بهيدر الجلسة) في سلة الحساب تلقائيًا
        $cartMerged = true;
        try {
            $this->carts->mergeGuestCart($customer, $request->header('X-Cart-Session'));
        } catch (\Throwable $exception) {
            report($exception);
            $cartMerged = false;
        }

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم تسجيل الدخول بنجاح', 'token' => $token, 'cart_merged' => $cartMerged]);
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

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated());

        return response()->json(['message' => 'تم تغيير كلمة المرور؛ سجل الدخول من جديد.']);
    }

    public function sendPhoneVerification(PhoneVerificationRequest $request): JsonResponse
    {
        SendCustomerPhoneVerification::dispatch($request->validated()['phone'], NetworkIdentity::key($request))
            ->onConnection(config('auth.customer_password_reset.connection', 'database'));

        return response()->json(['message' => 'لو الرقم متاح للتسجيل، سيتم إرسال رمز التحقق إليه.']);
    }

    public function verifyPhone(PhoneVerificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $proof = $this->phoneVerification->verify($data['phone'], $data['code']);

        return response()->json(['phone_verification_token' => $proof, 'expires_in' => 600]);
    }
}
