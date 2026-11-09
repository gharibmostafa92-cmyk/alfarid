<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Inventory;

use App\Enums\LocationType;
use App\Models\Branch;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends StoreLocationRequest
{
    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $branch = $this->route('branch');
        $location = $this->route('location');
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;
        $locationId = $location instanceof Location ? $location->getKey() : $location;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('locations', 'code')
                    ->where('branch_id', $branchId)
                    ->ignore($locationId),
            ],
            'type' => ['sometimes', 'required', Rule::enum(LocationType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
