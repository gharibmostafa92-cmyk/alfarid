<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Rules\UniqueStaffRoleName;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends StoreRoleRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'regex:/^[\p{Arabic}\p{Latin}\d _-]+$/u',
                new UniqueStaffRoleName($this->route('role')?->id),
            ],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'string',
                'distinct',
                Rule::exists('permissions', 'name')->where('guard_name', 'staff'),
            ],
        ];
    }
}
