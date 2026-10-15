<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Enums\PriceListType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_column(PriceListType::cases(), 'value'))],
            'customer_id' => [
                'required_if:type,'.PriceListType::CustomerSpecific->value,
                'prohibited_unless:type,'.PriceListType::CustomerSpecific->value,
                'nullable', 'integer', 'min:1', Rule::exists('customers', 'id'),
            ],
            'customer_group_id' => [
                'required_if:type,'.PriceListType::CustomerGroup->value,
                'prohibited_unless:type,'.PriceListType::CustomerGroup->value,
                'nullable', 'integer', 'min:1', Rule::exists('customer_groups', 'id'),
            ],
            'branch_id' => ['nullable', 'integer', 'min:1', Rule::exists('branches', 'id')],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['nullable', 'integer', 'min:-10000', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
