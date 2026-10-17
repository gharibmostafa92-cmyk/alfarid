<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BranchType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        $code = $this->input('code');
        $email = $this->input('email');

        if (is_string($code)) {
            $normalized['code'] = mb_strtoupper(trim($code));
        }

        if (is_string($email)) {
            $normalized['email'] = mb_strtolower(trim($email));
        }

        $this->merge($normalized);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('branches', 'code')],
            'type' => ['required', Rule::enum(BranchType::class)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
