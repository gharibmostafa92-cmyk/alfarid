<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Models\ProductVariant;
use Illuminate\Validation\Validator;

/**
 * تحقق وجود المتغيرات باستعلام واحد مجمّع (whereIn) بدل Rule::exists لكل عنصر —
 * جرد/تحويل من 500 صنف كان ينفذ 500 استعلام قبل بدء العمل أصلًا (M11).
 * النشاط (is_active) لا يُفحص هنا: عمليات الإخراج مسموحة على المعطّل (M5)،
 * والإدخال يُحظر في الخدمة نفسها.
 */
trait BulkVariantExists
{
    protected function addBulkVariantExistsCheck(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
                return;
            }

            $ids = collect($items)
                ->pluck('product_variant_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($ids->isEmpty()) {
                return;
            }

            $found = ProductVariant::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $missing = $ids->diff($found);

            if ($missing->isEmpty()) {
                return;
            }

            foreach ($items as $i => $item) {
                if (isset($item['product_variant_id']) && $missing->contains((int) $item['product_variant_id'])) {
                    $v->errors()->add("items.{$i}.product_variant_id", 'المتغير المحدد غير موجود.');
                }
            }
        });
    }
}
