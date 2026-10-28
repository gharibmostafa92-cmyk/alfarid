<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pricing;

use App\Enums\PriceListType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates updates to price list header attributes.
 */
class UpdatePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Polymorphic type-customer linkage is verified in PriceListService against merged state,
     * allowing partial updates (e.g. name only) without resending target IDs.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(PriceListType::class)],
            'customer_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('customers', 'id')],
            'customer_group_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('customer_groups', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', Rule::exists('branches', 'id')->where('is_active', true)],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['EGP'])], // Only Egyptian Pound (EGP) is currently supported.
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['sometimes', 'bail', 'integer', 'min:-10000', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
