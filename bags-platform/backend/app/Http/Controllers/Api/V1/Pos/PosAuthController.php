<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\LoginDestinationResolver;
use App\Services\Auth\StaffAuthenticationAudit;
use App\Services\Auth\StaffHandoffService;
use App\Services\Auth\StaffTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * POS terminal cashier authentication enforcing mandatory branch authorization checks.
 */
class PosAuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$dRibbzvWAKqkXBRPna4QCuri7dR8TKHRGDIt.RsplFZRfRcJzmPlq';

    /**
     * Authenticate cashier against specific branch credentials and issue scoped staff token.
     */
    public function login(Request $request): JsonResource|JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'password' => ['required', 'string', 'max:72'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])
            ->first();

        // Dummy hash evaluation prevents timing attack enumeration of registered staff emails.
        $storedHash = $user instanceof User ? $user->password : self::DUMMY_PASSWORD_HASH;
        $passwordOk = Hash::check($data['password'], $storedHash);

        // Cashiers are strictly confined to their assigned active branch.
        $branchAllowed = $user instanceof User
            && $user->is_active
            && ! $user->is_system
            && $user->can('pos.access')
            && $user->branch_id === (int) $data['branch_id'];

        if (! $user instanceof User || ! $passwordOk || ! $branchAllowed) {
            StaffAuthenticationAudit::record($user, 'failed');

            return response()->json([
                'message' => 'بيانات تسجيل الدخول غير صحيحة أو الحساب غير مصرح له على هذا الفرع.',
            ], 401);
        }

        // Verify designated branch exists and is currently active.
        Branch::query()->where('id', $data['branch_id'])->where('is_active', true)->firstOrFail();

        $user->load(['branch', 'roles.permissions', 'permissions']);
        app(LoginDestinationResolver::class)->resolve($user);
        $token = app(StaffTokenService::class)->issue($user, StaffHandoffService::credentialFingerprint($user));
        StaffAuthenticationAudit::record($user, 'success');

        return UserResource::make($user)->additional(['token' => $token]);
    }

    /**
     * Revoke cashier terminal session token.
     */
    public function logout(Request $request): Response
    {
        $request->user('staff')?->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
