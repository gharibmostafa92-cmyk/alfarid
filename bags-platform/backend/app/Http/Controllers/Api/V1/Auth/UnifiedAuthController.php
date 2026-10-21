<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            return $this->respond('staff', $redirect, null, $this->handoff->issue($actor, $redirect['path']),
                ['id' => $actor->id, 'name' => $actor->name], 'active');
        }

        if ($actor instanceof SupplierUser) {
            $actor->load('supplier');

            return $this->respond('supplier', $redirect, $this->issueSupplierToken($actor), null,
                SupplierUserResource::make($actor)->resolve($request), 'active');
        }

        /** @var Customer $actor */
        $token = $this->customerAuth->issueToken($actor, $actor->type === CustomerType::Retail ? 'store-token' : 'wholesale-token');
        $this->carts->mergeGuestCart($actor, $request->header('X-Cart-Session'));

        return $this->respond('customer', $redirect, $token, null,
            CustomerResource::make($actor)->resolve($request), $actor->status->value);
    }

    public function exchange(HandoffExchangeRequest $request): JsonResponse
    {
        $payload = $this->handoff->consume($request->validated()['code']);
        $user = $payload ? User::query()->find($payload['user_id']) : null;

        if (! $user instanceof User || ! $user->is_active) {
            return response()->json(['message' => 'رابط الدخول غير صالح أو انتهت صلاحيته.'], 401);
        }

        $user->load(['branch', 'roles.permissions', 'permissions']);
        $redirect = $this->destinations->resolve($user);

        return response()->json([
            'data' => UserResource::make($user)->resolve($request),
            'token' => $user->createToken('staff-token')->plainTextToken,
            'redirect' => $redirect,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $actor = $request->user('sanctum');

        if ($actor instanceof User) {
            abort_unless($actor->is_active, 403);
            $actor->load(['branch', 'roles.permissions', 'permissions']);

            return response()->json(['actor' => ['type' => 'staff'],
                'redirect' => $this->destinations->resolve($actor),
                'data' => UserResource::make($actor)->resolve($request)]);
        }

        if ($actor instanceof SupplierUser) {
            abort_unless($actor->is_active, 403);
            $actor->load('supplier');

            return response()->json(['actor' => ['type' => 'supplier'],
                'redirect' => $this->destinations->resolve($actor),
                'data' => SupplierUserResource::make($actor)->resolve($request)]);
        }

        if ($actor instanceof Customer) {
            abort_if($actor->status === CustomerStatus::Suspended, 403);

            return response()->json([
                'actor' => ['type' => 'customer', 'subtype' => $actor->type->value, 'status' => $actor->status->value],
                'redirect' => $this->destinations->resolve($actor),
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
        $limit = max(1, (int) config('auth.supplier_max_tokens', 5));
        $old = $user->tokens()->orderByDesc('id')->skip($limit - 1)->pluck('id');
        if ($old->isNotEmpty()) {
            $user->tokens()->whereIn('id', $old)->delete();
        }

        return $user->createToken('supplier-token')->plainTextToken;
    }

    /**
     * @param  array{app: string, path: string, destination: string}  $redirect
     * @param  array<string, mixed>  $data
     */
    private function respond(string $type, array $redirect, ?string $token, ?string $handoff, array $data, ?string $status): JsonResponse
    {
        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح',
            'actor' => ['type' => $type, 'subtype' => $redirect['destination'], 'status' => $status],
            'redirect' => $redirect,
            'token' => $token,
            'handoff_code' => $handoff,
            'data' => $data,
        ]);
    }
}
