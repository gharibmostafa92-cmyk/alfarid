<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
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
        $location = $this->route('location');
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('locations', 'code')
                    ->where('branch_id', $branchId)
                    ->ignore($location instanceof Location ? $location : null),
            ],
            'type' => ['sometimes', 'required', Rule::enum(LocationType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
