<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Customer;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\Customer\ForgotPasswordRequest;
use App\Http\Requests\Store\Customer\LoginRequest;
use App\Http\Requests\Store\Customer\PhoneVerificationRequest;
use App\Http\Requests\Store\Customer\RegisterRequest;
use App\Http\Requests\Store\Customer\ResetPasswordRequest;
use App\Http\Resources\Store\CustomerResource;
use App\Jobs\SendCustomerPhoneVerification;
use App\Models\Customer;
use App\Rules\TurnstileToken;
use App\Services\Auth\CustomerAuthService;
use App\Services\Customer\CustomerMessageBudget;
use App\Services\Customer\CustomerPhoneVerificationService;
use App\Services\Orders\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Handles retail customer authentication, self-registration, session tokens, and password recovery.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly CartService $carts,
        private readonly CustomerPhoneVerificationService $phoneVerification,
    ) {}

    /**
     * Retrieve frontend registration requirements and CAPTCHA configuration.
     */
    public function registrationOptions(): JsonResponse
    {
        $required = (bool) config('auth.require_phone_verification');

        return response()->json(['data' => [
            'phone_verification_required' => $required,
            'turnstile_site_key' => $required ? config('services.turnstile.site_key') : null,
        ]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Register a new retail customer account and issue an initial access token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        [$customer, $token] = DB::transaction(function () use ($request): array {
            $customer = $this->auth->registerRetail($request->validated());

            return [$customer, $this->auth->issueToken($customer, 'store-token')];
        });

        // Automatically merge existing guest cart items into the newly registered account.
        $cartMerged = false;
        $cartMergeWarnings = [];
        try {
            $merge = $this->carts->mergeGuestCartWithReport($customer, $request->header('X-Cart-Session'));
            $cartMerged = $merge['merged'];
            $cartMergeWarnings = $merge['warnings'];
        } catch (\Throwable $exception) {
            report($exception);
            if ($request->header('X-Cart-Session')) {
                $cartMergeWarnings[] = 'تعذر نقل محتويات سلة الزائر بالكامل؛ راجع السلة قبل إتمام الطلب.';
            }
        }

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم إنشاء حسابك بنجاح', 'token' => $token, 'cart_merged' => $cartMerged, 'cart_merge_warnings' => $cartMergeWarnings])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Authenticate customer credentials and issue a store session token.
     */
    public function login(LoginRequest $request): CustomerResource
    {
        $customer = $this->auth->login($request->validated()['login'], $request->validated()['password']);
        $token = $this->auth->issueToken($customer, 'store-token');

        // Merge guest cart items into the customer account upon login.
        $cartMerged = false;
        $cartMergeWarnings = [];
        try {
            $merge = $this->carts->mergeGuestCartWithReport($customer, $request->header('X-Cart-Session'));
            $cartMerged = $merge['merged'];
            $cartMergeWarnings = $merge['warnings'];
        } catch (\Throwable $exception) {
            report($exception);
            if ($request->header('X-Cart-Session')) {
                $cartMergeWarnings[] = 'تعذر نقل محتويات سلة الزائر بالكامل؛ راجع السلة قبل إتمام الطلب.';
            }
        }

        return CustomerResource::make($customer)
            ->additional(['message' => 'تم تسجيل الدخول بنجاح', 'token' => $token, 'cart_merged' => $cartMerged, 'cart_merge_warnings' => $cartMergeWarnings]);
    }

    /**
     * Revoke current access token while preserving concurrent device sessions.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    /**
     * Trigger asynchronous password reset notification if the account exists.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->forgotPassword($request->validated()['email'] ?? null, $request->validated()['phone'] ?? null);

        return response()->json(['message' => 'لو الحساب موجود، أرسلنا رمز الاستعادة إليه.']);
    }

    /**
     * Update account password using a validated password reset token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated());

        return response()->json(['message' => 'تم تغيير كلمة المرور؛ سجل الدخول من جديد.']);
    }

    /**
     * Dispatch an SMS OTP code to verify mobile number ownership.
     *
     * @throws BusinessException If IP subnet cannot be resolved.
     */
    public function sendPhoneVerification(PhoneVerificationRequest $request): JsonResponse
    {
        // Enforce Cloudflare Turnstile verification strictly on SMS dispatch to protect gateway budget.
        $request->validate(['turnstile_token' => [new TurnstileToken($request->ip())]]);
        $phone = $request->validated()['phone'];
        // Keep the response identical for registered and unregistered numbers
        // so this public endpoint cannot be used to enumerate customer phones.
        if (Customer::query()->where('phone', Customer::normalizePhone($phone))->exists()) {
            return response()->json(['message' => 'سيتم إرسال رمز التحقق إلى الرقم إذا كان متاحًا.']);
        }
        $networkKey = CustomerMessageBudget::subnetKey($request->ip());
        if ($networkKey === null) {
            throw new BusinessException('تعذر التحقق من الشبكة، حاول مرة أخرى.');
        }

        SendCustomerPhoneVerification::dispatch($phone, $networkKey)
            ->onConnection(config('auth.customer_password_reset.connection', 'database'));

        return response()->json(['message' => 'سيتم إرسال رمز التحقق إلى الرقم إذا كان متاحًا.']);
    }

    /**
     * Validate submitted SMS OTP and issue a one-time proof token.
     */
    public function verifyPhone(PhoneVerificationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $proof = $this->phoneVerification->verify($data['phone'], $data['code']);

        return response()->json(['phone_verification_token' => $proof, 'expires_in' => 600]);
    }
}
