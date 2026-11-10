<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Http\Resources\Admin\Auth\UserResource;
use App\Models\User;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\StaffTokenService;
use App\Services\Auth\UnifiedAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly LoginDestinationResolver $destinationResolver,
        private readonly StaffTokenService $tokenService,
    ) {}

    public function login(AdminLoginRequest $request): JsonResource|JsonResponse
    {
        $credentials = $request->validated();
        $actor = app(UnifiedAuthService::class)->attempt($credentials['email'], $credentials['password'], staffOnly: true);
        if (! $actor instanceof User) {
            return response()->json([
                'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير نشط.',
            ], 401);
        }

        $token = $this->tokenService->issue($actor, StaffHandoffService::credentialFingerprint($actor));
        $actor->load(['branch', 'roles.permissions', 'permissions']);
        $redirect = $this->destinationResolver->resolve($actor);

        return $this->userResource($actor)->additional(['token' => $token, 'redirect' => $redirect]);
    }

    public function me(Request $request): JsonResource
    {
        $user = $request->user('staff');

        abort_unless($user instanceof User, 403);

        return $this->userResource($user);
    }

    public function logout(Request $request): Response
    {
        $request->user('staff')?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function userResource(User $user): UserResource
    {
        return UserResource::make($user->loadMissing(['branch', 'roles.permissions', 'permissions']));
    }
}
