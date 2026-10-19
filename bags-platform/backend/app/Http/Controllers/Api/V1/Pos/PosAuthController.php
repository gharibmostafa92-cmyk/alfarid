<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * مصادقة الكاشير على نقطة البيع.
 * تختلف عن تسجيل دخول الإدارة بقيد الفرع الإلزامي.
 */
class PosAuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$dRibbzvWAKqkXBRPna4QCuri7dR8TKHRGDIt.RsplFZRfRcJzmPlq';

    public function login(Request $request): JsonResource|JsonResponse
    {
        $data = $request->validate([
            'email'     => ['required', 'string', 'email:rfc', 'max:191'],
            'password'  => ['required', 'string', 'max:72'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])
            ->first();

        $storedHash    = $user instanceof User ? $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordOk    = Hash::check($data['password'], $storedHash);

        // التحقق من صلاحية الدخول إلى هذا الفرع تحديداً
        $branchAllowed = $user instanceof User
            && $user->is_active
            && $user->branch_id === (int) $data['branch_id'];

        if (! $user instanceof User || ! $passwordOk || ! $branchAllowed) {
            return response()->json([
                'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير مصرح له على هذا الفرع.',
            ], 401);
        }

        // التحقق من وجود الفرع ونشاطه
        Branch::query()->where('id', $data['branch_id'])->whereNotNull('id')->firstOrFail();

        $emailKey = hash('sha256', Str::lower(trim($data['email'])));
        RateLimiter::clear('pos-login:'.$emailKey);

        $user->load(['branch', 'roles.permissions', 'permissions']);
        $token = $user->createToken('pos-token', ['pos'])->plainTextToken;

        return UserResource::make($user)->additional(['token' => $token]);
    }

    public function logout(Request $request): \Illuminate\Http\Response
    {
        $request->user('staff')?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
