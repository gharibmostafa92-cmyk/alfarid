<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Auth;

use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\PasswordByteLimit;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        abort_if($this->route('user') instanceof User && $this->route('user')->is_system, 404);

        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $targetId = $this->route('user') instanceof User ? $this->route('user')->getKey() : $this->route('user');
                    $exists = User::query()
                        ->when($targetId !== null, fn ($q) => $q->where('id', '!=', $targetId))
                        ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $value))])
                        ->exists();
                    if ($exists) {
                        $fail('البريد الإلكتروني مستخدم بالفعل.');
                    }
                },
                new EmailNotUsedByOtherAccountType('users'),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', Password::min(12)->letters()->numbers(), 'max:255', new PasswordByteLimit],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => [
                'string',
                'distinct',
                Rule::exists('roles', 'name')->where('guard_name', 'staff'),
            ],
        ];
    }
}
