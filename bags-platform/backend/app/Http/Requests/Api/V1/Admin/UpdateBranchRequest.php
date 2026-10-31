<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\BranchType;
use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
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

        if (! $this->has('lock_version') && $this->has('expected_version')) {
            $normalized['lock_version'] = $this->input('expected_version');
        }

        if (! $this->has('lock_version') && ! isset($normalized['lock_version'])) {
            $branch = $this->route('branch');
            $branchModel = $branch instanceof Branch ? $branch : Branch::find($branch);
            if ($branchModel) {
                $normalized['lock_version'] = $branchModel->lock_version;
            }
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
        $branch = $this->route('branch');
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('branches', 'code')->ignore($branchId),
            ],
            'type' => ['sometimes', 'required', Rule::enum(BranchType::class)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'is_active' => ['sometimes', 'boolean'],
            // M3: قفل توافقي إلزامي مثل الموظفين.
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
