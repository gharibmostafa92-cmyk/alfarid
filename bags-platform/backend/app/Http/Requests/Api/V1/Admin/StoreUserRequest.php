<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Rules\EmailNotUsedByOtherAccountType;
use App\Rules\PasswordByteLimit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function messages(): array
    {
        return ['email.unique' => 'هذا البريد مستخدم بحساب آخر في النظام.'];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email'), new EmailNotUsedByOtherAccountType('users')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:255', new PasswordByteLimit],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
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
