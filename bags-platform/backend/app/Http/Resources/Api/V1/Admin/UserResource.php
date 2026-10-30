<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\StaffRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => (int) $this->lock_version,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null
                ? null
                : ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'is_active' => $this->is_active,
            // L8: ليعرف الفرونت أن المستخدم مطالب بتدوير كلمة المرور.
            'must_change_password' => (bool) $this->must_change_password,
            'roles' => $this->whenLoaded(
                'roles',
                fn () => $this->roles->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])->values(),
            ),
            'permissions' => $this->when(
                $this->relationLoaded('roles')
                    && $this->relationLoaded('permissions')
                    && $this->roles->every(fn ($role): bool => $role->relationLoaded('permissions')),
                fn () => $this->hasRole(StaffRole::SuperAdmin->value, 'staff')
                    ? Permission::query()->where('guard_name', 'staff')->orderBy('name')->pluck('name')
                    : $this->getAllPermissions()->pluck('name')->unique()->values(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
