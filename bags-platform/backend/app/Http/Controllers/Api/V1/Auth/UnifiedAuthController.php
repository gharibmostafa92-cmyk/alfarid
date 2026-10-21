<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\HandoffExchangeRequest;
use App\Http\Requests\Api\V1\Auth\UnifiedLoginRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Http\Resources\Api\V1\Supplier\SupplierUserResource;
use App\Http\Resources\Store\CustomerResource;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\UnifiedAuthService;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class UnifiedAuthController extends Controller
{
    public function __construct(
        private readonly UnifiedAuthService $auth,
        private readonly LoginDestinationResolver $destinations,
        private readonly StaffHandoffService $handoff,
        private readonly CustomerAuthService $customerAuth,
        private readonly CartService $carts,
    ) {}

    public function login(UnifiedLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->auth->attempt($data['login'], $data['password']);
        $redirect = $this->destinations->resolve($actor, $data['redirect'] ?? null);

        if ($actor instanceof User) {
            return $this->respond('staff', $redirect, null, $this->handoff->issue($actor),
                ['id' => $actor->id, 'name' => $actor->name], 'active');
        }

        if ($actor instanceof SupplierUser) {
            $actor->load('supplier');

            return $this->respond('supplier', $redirect, $this->issueSupplierToken($actor), null,
                SupplierUserResource::make($actor)->resolve($request), 'active');
        }

        /** @var Customer $actor */
        $token = $this->customerAuth->issueToken($actor, $actor->type === CustomerType::Retail ? 'store-token' : 'wholesale-token');
        // M10: الدمج best-effort بعد إصدار التوكن — فشله لا يُفشل الدخول ولا
        // يُيتّم التوكن؛ يُبلغ الفرونت بالنتيجة ليحتفظ بـ cart_session عند الفشل.
        $cartMerged = true;
        try {
            $this->carts->mergeGuestCart($actor, $request->header('X-Cart-Session'));
        } catch (\Throwable $exception) {
            report($exception);
            $cartMerged = false;
        }

        return $this->respond('customer', $redirect, $token, null,
            CustomerResource::make($actor)->resolve($request), $actor->status->value,
            $actor->type->value, $cartMerged);
    }

    public function exchange(HandoffExchangeRequest $request): JsonResponse
    {
        // L3: الكود المستهلك يُعامل كغير صالح (401)، أما أعطال البنية
        // (قاعدة/كاش) فتُترك لتُرجع 503/500 بدل ابتلاعها في 401 مضلل.
        try {
            $payload = $this->handoff->consume($request->validated()['code']);
        } catch (LockTimeoutException $exception) {
            report($exception);
            $payload = null;
        }
        $user = $payload ? User::query()->find($payload['user_id']) : null;

        if (! $user instanceof User || ! $user->is_active) {
            logger()->info('staff.handoff', ['ip' => $request->ip(), 'result' => 'rejected']);

            return response()->json(['message' => 'رابط الدخول غير صالح أو انتهت صلاحيته.'], 401);
        }

        // تُحسم الوجهة (بما فيها فحص الأدوار/الفرع) قبل إصدار أي توكن حتى
        // لا يتخلف توكن يتيم عند الرفض.
        $user->load(['branch', 'roles.permissions', 'permissions']);
        $redirect = $this->destinations->resolve($user);

        // M9: حد لتوكنات الموظفين (مثل العملاء والموردين) داخل transaction،
        // مع تنظيف المنتهية أولًا حتى لا يُحرم النشطون.
        $token = DB::transaction(function () use ($user): string {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active) {
                throw new BusinessException('غير مصرح لك بالدخول كموظف أو الحساب غير نشط.', [], 401);
            }
            // M1: SANCTUM_EXPIRATION الفارغ يعني بلا انتهاء زمني — لا تمسح كل التوكنات.
            $expiry = config('sanctum.expiration');
            $user->tokens()->where(fn ($q) => $q->where('expires_at', '<=', now())
                ->when($expiry, fn ($q) => $q->orWhere('created_at', '<=', now()->subMinutes((int) $expiry))))->delete();
            $limit = max(1, (int) config('auth.staff_max_tokens', 20));
            $old = $user->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
            if ($old->isNotEmpty()) {
                $user->tokens()->whereIn('id', $old)->delete();
            }

            return $user->createToken('staff-token')->plainTextToken;
        });

        logger()->info('staff.handoff', ['user_id' => $user->id, 'ip' => $request->ip(), 'result' => 'success']);

        return response()->json([
            'data' => UserResource::make($user)->resolve($request),
            'token' => $token,
            'redirect' => $redirect,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $actor = $request->user('sanctum');

        if ($actor instanceof User) {
            abort_unless($actor->is_active, 403);
            $actor->load(['branch', 'roles.permissions', 'permissions']);
            $redirect = $this->destinations->resolve($actor);

            return response()->json(['actor' => ['type' => 'staff', 'subtype' => $redirect['destination']],
                'redirect' => $redirect,
                'data' => UserResource::make($actor)->resolve($request)]);
        }

        if ($actor instanceof SupplierUser) {
            // H5: أعد فحص الشركة هنا أيضًا — إيقافها بعد إصدار التوكن يجب أن
            // يمنع الجلسة فورًا لا عند انتهاء التوكن.
            abort_unless($actor->is_active && $actor->supplier_id !== null
                && $actor->supplier?->is_active === true, 403);
            $actor->load('supplier');
            $redirect = $this->destinations->resolve($actor);

            return response()->json(['actor' => ['type' => 'supplier', 'subtype' => $redirect['destination']],
                'redirect' => $redirect,
                'data' => SupplierUserResource::make($actor)->resolve($request)]);
        }

        if ($actor instanceof Customer) {
            abort_if($actor->status === CustomerStatus::Suspended, 403);
            $redirect = $this->destinations->resolve($actor);

            return response()->json([
                // M1: subtype هو الـ destination دائمًا (كما في login)، ونوع
                // العميل في حقل منفصل حتى لا يبني الفرونت الوجهة من me خطأً.
                'actor' => ['type' => 'customer', 'subtype' => $redirect['destination'],
                    'customer_type' => $actor->type->value, 'status' => $actor->status->value],
                'redirect' => $redirect,
                'data' => CustomerResource::make($actor)->resolve($request)]);
        }

        abort(401);
    }

    public function logout(Request $request): Response
    {
        $request->user('sanctum')?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function issueSupplierToken(SupplierUser $user): string
    {
        // L1: الحد غير ذري — دخولان متزامنان يتجاوزانه. القفل داخل transaction.
        return DB::transaction(function () use ($user): string {
            $user = SupplierUser::query()->lockForUpdate()->findOrFail($user->id);
            if (! $user->is_active || $user->supplier_id === null || $user->supplier?->is_active !== true) {
                throw new BusinessException('بيانات الدخول غير صحيحة.', [], 401);
            }
            // M1: SANCTUM_EXPIRATION الفارغ يعني بلا انتهاء زمني — لا تمسح كل التوكنات.
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

    /**
     * @param  array{app: string, path: string, destination: string}  $redirect
     * @param  array<string, mixed>  $data
     */
    private function respond(string $type, array $redirect, ?string $token, ?string $handoff, array $data, ?string $status, ?string $customerType = null, ?bool $cartMerged = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => 'تم تسجيل الدخول بنجاح',
            // M1: customer_type منفصل عن subtype (الوجهة) حتى لا يبني الفرونت
            // الوجهة من نوع العميل خطأً.
            'actor' => array_filter(['type' => $type, 'subtype' => $redirect['destination'], 'status' => $status,
                'customer_type' => $customerType], fn ($v) => $v !== null),
            'redirect' => $redirect,
            'token' => $token,
            'handoff_code' => $handoff,
            'data' => $data,
            'cart_merged' => $cartMerged,
        ], fn ($v, $k) => $v !== null || $k === 'handoff_code' || $k === 'token', ARRAY_FILTER_USE_BOTH));
    }
}
