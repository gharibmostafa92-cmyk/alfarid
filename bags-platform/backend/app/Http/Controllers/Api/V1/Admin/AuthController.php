<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminLoginRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\User;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\StaffTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$dRibbzvWAKqkXBRPna4QCuri7dR8TKHRGDIt.RsplFZRfRcJzmPlq';

    public function __construct(
        private readonly LoginDestinationResolver $destinationResolver,
        private readonly StaffTokenService $tokenService,
    ) {}

    public function login(AdminLoginRequest $request): JsonResource|JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$credentials['email']])
            ->first();
        $storedHash = $user instanceof User ? $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordMatches = Hash::check($credentials['password'], $storedHash);

        if (! $user instanceof User || ! $user->is_active || $user->is_system || ! $passwordMatches) {
            return response()->json([
                'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير نشط.',
            ], 401);
        }

        $emailKey = hash('sha256', Str::transliterate(Str::lower(trim($credentials['email']))));
        RateLimiter::clear('admin-login-email:'.$emailKey);
        RateLimiter::clear('admin-login-email-hour:'.$emailKey);

        $redirect = $this->destinationResolver->resolve($user);
        $token = $this->tokenService->issue($user, StaffHandoffService::credentialFingerprint($user));

        return $this->userResource($user)->additional(['token' => $token, 'redirect' => $redirect]);
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
