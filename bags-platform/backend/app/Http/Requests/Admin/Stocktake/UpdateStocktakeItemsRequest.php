<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Stocktake;

use App\Http\Requests\Admin\Concerns\BulkVariantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * تحديث جزئي لعناصر جرد (M-4): العناصر المرسلة تُحدَّث/تُنشأ فقط، والغائبة
 * تبقى كما هي — بعكس PUT الكامل الذي يستبدل القائمة كلها.
 */
class UpdateStocktakeItemsRequest extends FormRequest
{
    use BulkVariantExists;

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
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_variant_id' => ['required', 'bail', 'integer', 'min:1', 'distinct'],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'items.*.recount' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->addBulkVariantExistsCheck($validator);
    }
}
