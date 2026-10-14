<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\Api\V1\Admin\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['branch', 'roles.permissions', 'permissions'])
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
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $roles = $validated['roles'] ?? [];
        $userAttributes = Arr::except($validated, ['roles']);
        $userAttributes['is_active'] ??= true;

        $user = DB::transaction(function () use ($userAttributes, $roles): User {
            $user = User::create($userAttributes);
            $user->syncRoles($roles);

            return $user->load(['branch', 'roles.permissions', 'permissions']);
        });

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource|JsonResponse
    {
        $validated = $request->validated();

        if (
            $user->hasRole(StaffRole::SuperAdmin->value, 'staff')
            && (
                (array_key_exists('is_active', $validated) && ! $request->boolean('is_active'))
                || (array_key_exists('roles', $validated)
                    && ! in_array(StaffRole::SuperAdmin->value, $validated['roles'], true))
            )
        ) {
            return response()->json([
                'message' => 'لا يمكن تعطيل حساب Super Admin الأساسي أو إزالة دوره.',
            ], 403);
        }

        $roles = $validated['roles'] ?? null;
        $userAttributes = Arr::except($validated, ['roles']);

        if (array_key_exists('password', $userAttributes) && $userAttributes['password'] === null) {
            unset($userAttributes['password']);
        }

        DB::transaction(function () use ($user, $userAttributes, $roles): void {
            $user->update($userAttributes);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }
        });

        return UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $currentUser = $request->user('staff');

        if ($currentUser instanceof User && $currentUser->is($user)) {
            return response()->json([
                'message' => 'لا يمكنك تعطيل حسابك الحالي.',
            ], 403);
        }

        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff')) {
            return response()->json([
                'message' => 'لا يمكن تعطيل حساب Super Admin الأساسي.',
            ], 403);
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false])->save();
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'تم تعطيل حساب الموظف وإلغاء رموزه.',
            'data' => UserResource::make($user->load(['branch', 'roles.permissions', 'permissions']))->resolve($request),
        ]);
    }
}
