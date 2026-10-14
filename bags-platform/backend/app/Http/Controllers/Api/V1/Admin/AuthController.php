<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminLoginRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthController extends Controller
{
    public function login(AdminLoginRequest $request): JsonResource|JsonResponse
    {
        $credentials = $request->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user instanceof User || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير نشط.',
            ], 401);
        }

        $user->load(['branch', 'roles.permissions', 'permissions']);
        $token = $user->createToken('staff-token')->plainTextToken;

        return UserResource::make($user)->additional(['token' => $token]);
    }

    public function me(Request $request): JsonResource
    {
        $user = $request->user('staff');

        abort_unless($user instanceof User, 403);

        $user->load(['branch', 'roles.permissions', 'permissions']);

        return UserResource::make($user);
    }
}
