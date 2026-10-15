<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Category;

use App\Http\Requests\Admin\Concerns\LimitsArrayInput;
use App\Models\Category;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderCategoriesRequest extends FormRequest
{
    use LimitsArrayInput;

    protected function arrayLimits(): array
    {
        return ['items' => 500];
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
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $items = $this->input('items');
            $ids = array_values(array_unique(array_filter(array_merge(array_column($items, 'id'), array_column($items, 'parent_id')), fn ($id) => $id !== null)));
            $available = array_fill_keys(Category::query()->whereIn('id', $ids)->pluck('id')->all(), true);
            foreach ($items as $index => $item) {
                foreach (['id', 'parent_id'] as $field) {
                    if ($item[$field] !== null && ! isset($available[$item[$field]])) {
                        $validator->errors()->add("items.{$index}.{$field}", 'القسم المحدد غير موجود أو محذوف');
                    }
                }
            }
        }];
    }
}
