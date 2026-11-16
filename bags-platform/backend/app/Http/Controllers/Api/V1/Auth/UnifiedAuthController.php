<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\HandoffExchangeRequest;
use App\Http\Requests\Api\V1\Auth\UnifiedLoginRequest;
use App\Http\Resources\Admin\Auth\UserResource;
use App\Http\Resources\Store\CustomerResource;
use App\Http\Resources\Supplier\SupplierUserResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use App\Services\Auth\CustomerAuthService;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\StaffTokenService;
use App\Services\Auth\UnifiedAuthService;
use App\Services\Orders\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

class UnifiedAuthController extends Controller
{
    public function __construct(
        private readonly UnifiedAuthService $auth,
        private readonly LoginDestinationResolver $destinations,
        private readonly StaffHandoffService $handoff,
        private readonly CustomerAuthService $customerAuth,
        private readonly CartService $carts,
    ) {}

    /**
     * Authenticate staff for a handoff or issue a customer token.
     */
    public function login(UnifiedLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->auth->attempt($data['login'], $data['password']);
        $redirect = $this->destinations->resolve($actor, $data['redirect'] ?? null);

        if ($actor instanceof User) {
            return $this->respond('staff', $redirect, null, $this->handoff->issue($actor, $request),
                ['id' => $actor->id, 'name' => $actor->name], 'active');
        }

        $token = $this->customerAuth->issueToken($actor, $actor->type === CustomerType::Retail ? 'store-token' : 'wholesale-token');
        // Best-effort cart merge for accounts allowed to shop: non-shopping actors
        // must not consume guest carts, and failed merges report cart_merged=false.
        $cartMerged = false;
        $guestToken = trim((string) $request->header('X-Cart-Session', ''));
        if ($actor->canShop() && $guestToken !== '') {
            try {
                $cartMerged = $this->carts->mergeGuestCart($actor, $guestToken);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $this->respond('customer', $redirect, $token, null,
            CustomerResource::make($actor)->resolve($request), $actor->status->value,
            $actor->type->value, $cartMerged);
    }

    /**
     * Exchange a staff one-time handoff code for a Sanctum access token.
     */
    public function exchange(HandoffExchangeRequest $request): JsonResponse
    {
        $payload = $this->handoff->consume($request->validated()['code'], $request);
        $user = $payload ? User::query()->find($payload['user_id']) : null;

        if (! $user instanceof User || ! $user->is_active || $user->is_system || ! is_string($payload['credentials'] ?? null)) {
            logger()->info('staff.handoff', ['ip' => $request->ip(), 'result' => 'rejected']);

            return response()->json(['message' => 'رابط الدخول غير صالح أو انتهت صلاحيته.'], 401);
        }

        $token = app(StaffTokenService::class)->issue($user, $payload['credentials']);
        $user->refresh()->load(['branch', 'roles.permissions', 'permissions']);
        $redirect = $this->destinations->resolve($user);

        logger()->info('staff.handoff', ['user_id' => $user->id, 'ip' => $request->ip(), 'result' => 'success']);

        return response()->json([
            'data' => UserResource::make($user)->resolve($request),
            'token' => $token,
            'redirect' => $redirect,
        ]);
    }

    /**
     * Retrieve the current authenticated actor profile and portal route destination.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->user('sanctum');
        if ($actor === null) {
            abort(401);
        }

        $token = $actor->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 403);

        if ($actor instanceof User) {
            abort_unless($actor->is_active && ! $actor->is_system, 403);
            abort_unless($token->can('*') || $token->can('pos'), 403);

            if ((bool) $actor->must_change_password) {
                return response()->json([
                    'message' => 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.',
                    'code' => 'password_change_required',
                ], 403);
            }

            if (! $actor->hasRole(StaffRole::SuperAdmin->value, 'staff')
                && ! $actor->can('inventory.all_branches')
                && $actor->branch_id !== null
                && ! $this->branchIsActive((int) $actor->branch_id)) {
                return response()->json(['message' => 'الفرع المرتبط بحسابك متوقف حالياً.'], 403);
            }

            $actor->load(['branch', 'roles.permissions', 'permissions']);
            $redirect = $this->destinations->resolve($actor);

            $data = $token->can('*')
                ? UserResource::make($actor)->resolve($request)
                : [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                    'branch_id' => $actor->branch_id,
                    'must_change_password' => (bool) $actor->must_change_password,
                ];

            return response()->json(['actor' => ['type' => 'staff', 'subtype' => $redirect['destination']],
                'redirect' => $redirect,
                'data' => $data]);
        }

        if ($actor instanceof SupplierUser) {
            abort_unless($token->can('supplier'), 403);
            // Re-verify company active status; deactivated suppliers must be blocked immediately.
            abort_unless($actor->is_active && $actor->supplier_id !== null
                && $actor->supplier?->is_active === true, 403);
            $actor->load('supplier');
            $redirect = $this->destinations->resolve($actor);

            return response()->json(['actor' => ['type' => 'supplier', 'subtype' => $redirect['destination']],
                'redirect' => $redirect,
                'data' => SupplierUserResource::make($actor)->resolve($request)]);
        }

        if ($actor instanceof Customer) {
            abort_unless($token->can('store-token') || $token->can('wholesale-token') || $token->can('store') || $token->can('wholesale'), 403);
            abort_if($actor->status === CustomerStatus::Suspended, 403);
            $redirect = $this->destinations->resolve($actor);

            return response()->json([
                // Keep customer_type distinct from destination subtype to prevent frontend misrouting.
                'actor' => ['type' => 'customer', 'subtype' => $redirect['destination'],
                    'customer_type' => $actor->type->value, 'status' => $actor->status->value],
                'redirect' => $redirect,
                'data' => CustomerResource::make($actor)->resolve($request)]);
        }

        abort(401);
    }

    private function branchIsActive(int $branchId): bool
    {
        try {
            return (bool) Cache::remember('branch-active:'.$branchId, 30, fn () => (bool) Branch::query()->whereKey($branchId)->value('is_active'));
        } catch (\Throwable) {
            return (bool) Branch::query()->whereKey($branchId)->value('is_active');
        }
    }

    /**
     * Revoke the current Sanctum token and log out the actor.
     */
    public function logout(Request $request): Response
    {
        $request->user('sanctum')?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /**
     * @param  array{app: string, path: string, destination: string}  $redirect
     * @param  array<string, mixed>  $data
     */
    private function respond(string $type, array $redirect, ?string $token, ?string $handoff, array $data, ?string $status, ?string $customerType = null, ?bool $cartMerged = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => 'تم تسجيل الدخول بنجاح',
            // Keep customer_type distinct from destination subtype to prevent frontend misrouting.
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
