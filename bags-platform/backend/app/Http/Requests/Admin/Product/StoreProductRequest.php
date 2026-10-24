<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Product;

use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Concerns\LimitsArrayInput;
use App\Http\Requests\Admin\Variant\VariantRules;
use App\Models\Product;
use App\Rules\UniqueCatalogIdentifier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreProductRequest extends FormRequest
{
    use LimitsArrayInput;

    protected function arrayLimits(): array
    {
        return ['variants' => 100];
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
        $rules = [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'brand_id' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', new UniqueCatalogIdentifier('products', 'slug')],
            'description_ar' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'material_ar' => ['nullable', 'string', 'max:150'],
            'material_en' => ['nullable', 'string', 'max:150'],
            'length_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'width_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'warranty_details' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['draft'])],
            // F9: idempotency key for product creation — one UUID per creation
            // session, reused across retries so a lost response cannot duplicate.
            'create_key' => ['nullable', 'uuid'],
            'sales_channel' => ['required', Rule::enum(SalesChannel::class)],
            'is_featured' => ['nullable', 'boolean'],
            // اختياري: متغيرات مع الإنشاء
            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*' => ['array:'.implode(',', array_keys(VariantRules::base()))],
        ];

        foreach (VariantRules::base(null, 'variants.*.') as $field => $rule) {
            if (in_array($field, ['sku', 'barcode'], true)) {
                $rule[] = 'distinct';
            }
            $rules["variants.*.$field"] = $rule;
        }

        // A replay with an explicit slug/SKU now legitimately collides with
        // its own saved rows. The service verifies the request fingerprint
        // and returns the saved product before any write.
        $key = $this->input('create_key');
        if (is_string($key) && Str::isUuid($key)
            && Product::withTrashed()->where('created_by', $this->user()->id)->where('create_key', $key)->exists()) {
            foreach ($rules as $field => $rule) {
                if (is_array($rule)) {
                    $rules[$field] = array_values(array_filter($rule, fn ($item) => ! $item instanceof UniqueCatalogIdentifier
                        && ! $item instanceof Unique));
                }
            }
        }

        return $rules;
    }
}
