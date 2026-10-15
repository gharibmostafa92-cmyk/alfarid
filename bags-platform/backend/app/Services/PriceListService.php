<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * إدارة قوائم الأسعار وعناصرها واستيراد/تصدير CSV.
 * منطق حساب السعر نفسه في PricingService.
 */
class PriceListService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return PriceList::query()
            ->with(['customer', 'customerGroup', 'branch'])
            ->withCount('items')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['customer_group_id'] ?? null, fn ($q, $v) => $q->where('customer_group_id', $v))
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->whereLike('name', '%'.SearchTerm::escape($v).'%', caseSensitive: false))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function find(int $id): PriceList
    {
        return PriceList::query()
            ->with(['customer', 'customerGroup', 'branch'])
            ->withCount('items')
            ->findOrFail($id);
    }

    public function create(array $data): PriceList
    {
        return PriceList::query()->create($data)->load(['customer', 'customerGroup', 'branch']);
    }

    public function update(PriceList $priceList, array $data): PriceList
    {
        // فرض ربط النوع بالمالك على الحالة النهائية: النوع الخاص بعميل لازم له عميل،
        // والخاص بمجموعة لازم له مجموعة، وأي نوع تاني بيصفّي الحقلين تلقائيًا
        $type = $data['type'] ?? $priceList->type;
        $typeValue = $type instanceof PriceListType ? $type->value : $type;

        if ($typeValue === PriceListType::CustomerSpecific->value) {
            $customerId = $data['customer_id'] ?? $priceList->customer_id;
            if ($customerId === null) {
                throw new BusinessException('قائمة الأسعار الخاصة بعميل تتطلب تحديد العميل.');
            }
            $data['customer_id'] = $customerId;
            $data['customer_group_id'] = null;
        } elseif ($typeValue === PriceListType::CustomerGroup->value) {
            $groupId = $data['customer_group_id'] ?? $priceList->customer_group_id;
            if ($groupId === null) {
                throw new BusinessException('قائمة أسعار المجموعة تتطلب تحديد مجموعة العملاء.');
            }
            $data['customer_group_id'] = $groupId;
            $data['customer_id'] = null;
        } else {
            $data['customer_id'] = null;
            $data['customer_group_id'] = null;
        }

        $priceList->update($data);

        return $priceList->load(['customer', 'customerGroup', 'branch']);
    }

    public function delete(PriceList $priceList): void
    {
        $priceList->delete(); // العناصر بتتحذف بـ cascade في القاعدة
    }

    // ---------- Items ----------

    public function paginateItems(PriceList $priceList, array $filters): LengthAwarePaginator
    {
        return $priceList->items()
            ->with(['variant.product'])
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->orderBy('product_variant_id')
            ->orderBy('min_qty')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function createItem(PriceList $priceList, array $data): PriceListItem
    {
        $this->assertNoDuplicateTier($priceList, (int) $data['product_variant_id'], (float) $data['min_qty']);

        return $priceList->items()->create($data)->load('variant.product');
    }

    public function updateItem(PriceListItem $item, array $data): PriceListItem
    {
        if (isset($data['product_variant_id']) || isset($data['min_qty'])) {
            $this->assertNoDuplicateTier(
                $item->priceList,
                (int) ($data['product_variant_id'] ?? $item->product_variant_id),
                (float) ($data['min_qty'] ?? $item->min_qty),
                $item->id,
            );
        }

        $item->update($data);

        return $item->load('variant.product');
    }

    public function deleteItem(PriceListItem $item): void
    {
        $item->delete();
    }

    private function assertNoDuplicateTier(PriceList $priceList, int $variantId, float $minQty, ?int $ignoreItemId = null): void
    {
        $exists = $priceList->items()
            ->where('product_variant_id', $variantId)
            ->where('min_qty', $minQty)
            ->when($ignoreItemId !== null, fn ($q) => $q->whereKeyNot($ignoreItemId))
            ->exists();

        if ($exists) {
            throw new BusinessException('توجد شريحة بنفس المتغير وحد الكمية داخل هذه القائمة بالفعل.');
        }
    }

    // ---------- CSV ----------

    /** تصدير عناصر القائمة كـ CSV بنفس أعمدة الاستيراد عشان إعادة الاستيراد تشتغل. */
    public function exportCsv(PriceList $priceList): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['sku', 'variant_id', 'product_name', 'min_qty', 'price', 'starts_at', 'ends_at']);

        $priceList->items()
            ->with(['variant.product'])
            ->orderBy('product_variant_id')
            ->orderBy('min_qty')
            ->chunk(500, function ($items) use ($handle): void {
                foreach ($items as $item) {
                    fputcsv($handle, [
                        $item->variant?->sku,
                        $item->product_variant_id,
                        $item->variant?->product?->name_ar,
                        $item->min_qty,
                        $item->price,
                        $item->starts_at?->format('Y-m-d H:i:s'),
                        $item->ends_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * استيراد CSV: كل صف بيحدّث أو ينشئ عنصرًا بمفتاح (القائمة، المتغير، min_qty)
     * داخل transaction واحدة. أخطاء الصفوف الفردية بتتجمع في التقرير من غير ما توقف الكل.
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function importCsv(PriceList $priceList, UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new BusinessException('تعذرت قراءة ملف الاستيراد.');
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            throw new BusinessException('ملف الاستيراد فارغ.');
        }

        $columns = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $index = array_flip($columns);

        foreach (['price'] as $required) {
            if (! isset($index[$required])) {
                fclose($handle);
                throw new BusinessException("عمود {$required} مطلوب في ملف الاستيراد.");
            }
        }

        if (! isset($index['variant_id']) && ! isset($index['sku'])) {
            fclose($handle);
            throw new BusinessException('الملف لازم يحتوي عمود variant_id أو sku على الأقل.');
        }

        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $line = 1;

        DB::transaction(function () use ($handle, $index, $priceList, &$report, &$line): void {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                    continue; // سطر فارغ
                }

                $get = fn (string $column) => isset($index[$column]) ? trim((string) ($row[$index[$column]] ?? '')) : '';

                try {
                    $variant = $this->resolveVariant($get('variant_id'), $get('sku'));

                    if ($variant === null) {
                        throw new BusinessException('متغير غير موجود (sku/variant_id لا يطابق أي متغير).');
                    }

                    $priceRaw = $get('price');
                    if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                        throw new BusinessException('سعر غير صالح.');
                    }

                    $minQtyRaw = $get('min_qty');
                    $minQty = $minQtyRaw === '' ? 1.0 : (float) $minQtyRaw;
                    if (! is_numeric($minQtyRaw === '' ? '1' : $minQtyRaw) || $minQty <= 0) {
                        throw new BusinessException('حد كمية غير صالح.');
                    }

                    $item = PriceListItem::query()->updateOrCreate(
                        [
                            'price_list_id' => $priceList->id,
                            'product_variant_id' => $variant->id,
                            'min_qty' => $minQty,
                        ],
                        [
                            'price' => (float) $priceRaw,
                            'starts_at' => $this->parseDate($get('starts_at')),
                            'ends_at' => $this->parseDate($get('ends_at')),
                        ],
                    );

                    $item->wasRecentlyCreated ? $report['created']++ : $report['updated']++;
                } catch (BusinessException $e) {
                    $report['skipped']++;
                    $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                }
            }
        });

        fclose($handle);

        return $report;
    }

    private function resolveVariant(string $variantId, string $sku): ?ProductVariant
    {
        if ($variantId !== '' && ctype_digit($variantId)) {
            return ProductVariant::query()->find((int) $variantId);
        }

        if ($sku !== '') {
            return ProductVariant::query()->where('sku', $sku)->first();
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            throw new BusinessException("تاريخ غير صالح: {$value}");
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
