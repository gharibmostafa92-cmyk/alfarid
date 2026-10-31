<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\DeactivateStaffUser;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\StaffAdministration;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class UserController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}

    private function assertAccessible(?User $actor, User $target): void
    {
        abort_if($target->is_system, 404);
        $branch = $this->branches->restrictedBranch($actor);
        abort_if($branch !== null && (int) $target->branch_id !== $branch, 403);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
            'role' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'staff'),
            ],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = $this->branches->restrictedBranch($request->user('staff'));

        $users = User::query()
            ->with(['branch', 'roles'])
            ->where('is_system', false)
            ->when($branch !== null, fn ($query) => $query->where('branch_id', $branch))
            ->when(array_key_exists('branch_id', $filters), function ($query) use ($filters): void {
                if ($filters['branch_id'] === null) {
                    $query->whereNull('branch_id');

                    return;
                }

                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['role']), function ($query) use ($filters): void {
                $query->whereHas('roles', function ($roles) use ($filters): void {
                    $roles->where('name', $filters['role'])->where('guard_name', 'staff');
                });
            })
            ->when(! empty($filters['search']), function ($query) use ($filters): void {
                $term = SearchTerm::pattern(mb_strtolower(trim($filters['search'])));
                $query->where(function ($q) use ($term): void {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $roles = $validated['roles'] ?? [];
        $currentUser = $request->user('staff');
        $branch = $this->branches->restrictedBranch($currentUser);
        abort_if($branch !== null && (int) ($validated['branch_id'] ?? 0) !== $branch, 403);

        if (
            in_array(StaffRole::SuperAdmin->value, $roles, true)
            && (! $currentUser instanceof User || ! $currentUser->hasRole(StaffRole::SuperAdmin->value, 'staff'))
        ) {
            return response()->json([
                'message' => 'يجب أن تكون Super Admin لإسناد هذا الدور.',
            ], 403);
        }

        if (! StaffAdministration::mayManageUser($currentUser, roles: $roles)) {
            return $this->insufficientPermissionsResponse();
        }

        $user = app(CreateStaffUser::class)($currentUser, $validated)['user'];

        return $this->userResource($user)->response()->setStatusCode(201);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->assertAccessible($request->user('staff'), $user);

        return $this->userResource($user);
    }

    /**
     * L8: self-service password rotation — no users.update required, so a
     * flagged newcomer can always comply. Only the own password may change
     * here, and only with the verified current password.
     */
    public function updateOwnPassword(Request $request): UserResource|JsonResponse
    {
        $user = $request->user('staff');
        abort_unless($user instanceof User, 403);
        $validated = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(12)->letters()->numbers(), 'max:255', new \App\Rules\PasswordByteLimit],
        ]);
        $limitKey = 'staff:current-password:'.$user->id;
        if (RateLimiter::tooManyAttempts($limitKey, max(1, (int) config('auth.staff_current_password_failures', 5)))) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($limitKey));
        }
        if (! Hash::check($validated['current_password'], $user->password)) {
            RateLimiter::hit($limitKey, 3600);

            throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية الصحيحة لتغيير بيانات الدخول.']);
        }
        RateLimiter::clear($limitKey);
        $failure = app(UpdateStaffUser::class)(
            $user,
            $user,
            ['password' => Hash::make($validated['password']), 'expected_version' => $validated['expected_version']],
            ['current_token_id' => $request->user('staff')?->currentAccessToken()?->id],
            verifiedPasswordHash: $user->password,
            selfRotation: true
        );
        if ($failure !== null) {
            return response()->json(['message' => 'تعذر تغيير كلمة المرور.'], 403);
        }

        return $this->userResource($user->refresh());
    }

    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        abort_if($user->is_system, 404);
        $validated = $request->validated();
        $currentUser = $request->user('staff');
        $isCurrentUser = $currentUser instanceof User && $currentUser->is($user);

        if ($isCurrentUser && (array_key_exists('roles', $validated) || array_key_exists('is_active', $validated))) {
            return response()->json([
                'message' => 'لا يمكنك تغيير أدوارك أو حالة تنشيط حسابك الحالي.',
            ], 403);
        }

        $roles = $validated['roles'] ?? null;
        $userAttributes = Arr::except($validated, ['roles', 'current_password', 'expected_version']);

        if (array_key_exists('password', $userAttributes) && $userAttributes['password'] === null) {
            unset($userAttributes['password']);
        }

        $verifiedPasswordHash = null;
        $credentialChange = $isCurrentUser && (isset($userAttributes['password'])
            || (isset($userAttributes['email']) && $userAttributes['email'] !== $user->email));
        $passwordLimitKey = 'staff:current-password:'.$user->id;
        if ($credentialChange && RateLimiter::tooManyAttempts($passwordLimitKey, max(1, (int) config('auth.staff_current_password_failures', 5)))) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($passwordLimitKey));
        }
        if ($isCurrentUser && (isset($userAttributes['password'])
            || (isset($userAttributes['email']) && $userAttributes['email'] !== $user->email))
            && is_string($validated['current_password'] ?? null)
            && Hash::check($validated['current_password'], $user->password)) {
            $verifiedPasswordHash = $user->password;
        }

        if ($credentialChange && is_string($validated['current_password'] ?? null)) {
            if ($verifiedPasswordHash === null) {
                RateLimiter::hit($passwordLimitKey, 3600);
            } else {
                RateLimiter::clear($passwordLimitKey);
            }
        }
        if ($credentialChange && $verifiedPasswordHash === null) {
            throw ValidationException::withMessages(['current_password' => 'أدخل كلمة المرور الحالية الصحيحة لتغيير بيانات الدخول.']);
        }
        if (isset($userAttributes['password'])) {
            $userAttributes['password'] = Hash::make($userAttributes['password']);
        }

        $actionData = Arr::except($validated, ['password']);
        if (isset($userAttributes['password'])) {
            $actionData['password'] = $userAttributes['password'];
        }
        // L8: self password-only rotation (verified current password) does not
        // require users.update — otherwise flagged newcomers could never comply.
        $selfRotation = $isCurrentUser && isset($userAttributes['password'])
            && array_diff(array_keys($validated), ['password', 'current_password', 'expected_version']) === [];
        $failure = app(UpdateStaffUser::class)($currentUser, $user, $actionData,
            ['current_token_id' => $selfRotation ? $request->user('staff')?->currentAccessToken()?->id : null],
            verifiedPasswordHash: $verifiedPasswordHash, selfRotation: $selfRotation);

        if ($failure !== null) {
            if ($failure === 'insufficient_permissions') {
                return $this->insufficientPermissionsResponse();
            }

            return response()->json([
                'message' => $failure === 'protected_super_admin'
                    ? 'يجب الإبقاء على حساب Super Admin نشط واحد على الأقل.'
                    : 'لا يمكن للمستخدم الحالي تعديل حساب Super Admin أو إسناد دوره.',
            ], 403);
        }

        return $this->userResource($user->refresh());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is_system, 404);
        $currentUser = $request->user('staff');
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:0']]);

        if ($currentUser instanceof User && $currentUser->is($user)) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الحالي.',
            ], 403);
        }

        $deleted = app(DeactivateStaffUser::class)($currentUser, $user, (int) $validated['expected_version']);

        if (! $deleted) {
            return response()->json([
                'message' => 'لا يمكن تعطيل حساب Super Admin أو حساب يمتلك صلاحيات لا تملكها.',
            ], 403);
        }

        return response()->json([
            'message' => 'تم تعطيل حساب الموظف وإلغاء رموزه.',
            'data' => UserResource::make($user->refresh()->load(['branch', 'roles.permissions', 'permissions']))->resolve($request),
        ]);
    }

    private function insufficientPermissionsResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'لا يمكنك إدارة حساب أو إسناد دور يمتلك صلاحيات لا تملكها.',
        ], 403);
    }

    private function userResource(User $user): UserResource
    {
        return UserResource::make($user->loadMissing(['branch', 'roles.permissions', 'permissions']));
    }
}
