<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * إدارة قوائم الأسعار وعناصرها واستيراد/تصدير CSV.
 * منطق حساب السعر نفسه في PricingService.
 */
class PriceListService
{
    public function paginate(array $filters, ?User $user = null): LengthAwarePaginator
    {
        return PriceList::query()
            ->with(['customer', 'customerGroup', 'branch'])
            ->withCount('items')
            ->when($this->restrictedBranch($user) !== null, fn ($q) => $q->where('branch_id', $this->restrictedBranch($user)))
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

    public function find(int $id, ?User $user = null): PriceList
    {
        return PriceList::query()
            ->with(['customer', 'customerGroup', 'branch'])
            ->withCount('items')
            ->when($this->restrictedBranch($user) !== null, fn ($q) => $q->where('branch_id', $this->restrictedBranch($user)))
            ->findOrFail($id);
    }

    public function create(array $data, ?User $user = null): PriceList
    {
        $restricted = $this->restrictedBranch($user);
        if ($restricted !== null) {
            if (isset($data['branch_id']) && (int) $data['branch_id'] !== $restricted) {
                throw new AuthorizationException('لا تملك صلاحية إنشاء قوائم أسعار لفرع آخر.');
            }
            $data['branch_id'] = $restricted;
        }
        $data = $this->normalizeDates($data);
        $data = array_filter($data, fn ($value, $key) => $value !== null || ! in_array($key, ['currency', 'priority', 'is_active'], true), ARRAY_FILTER_USE_BOTH);
        $this->validateDates($data);
        $data = $this->withValidOwner($data);

        return PriceList::query()->create($data)->load(['customer', 'customerGroup', 'branch']);
    }

    public function update(PriceList $priceList, array $data, ?User $user = null): PriceList
    {
        $this->assertListAccess($user, $priceList);
        if ($this->restrictedBranch($user) !== null && isset($data['branch_id']) && (int) $data['branch_id'] !== $this->restrictedBranch($user)) {
            throw new AuthorizationException('لا تملك صلاحية نقل قائمة أسعار إلى فرع آخر.');
        }
        $data = $this->normalizeDates($data);

        return DB::transaction(function () use ($priceList, $data): PriceList {
            $priceList = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);
            $this->validateDates($data, $priceList);
            $data = $this->withValidOwner($data, $priceList);
            $priceList->update($data);

            return $priceList->load(['customer', 'customerGroup', 'branch']);
        });
    }

    private function withValidOwner(array $data, ?PriceList $priceList = null): array
    {
        // فرض ربط النوع بالمالك على الحالة النهائية: النوع الخاص بعميل لازم له عميل،
        // والخاص بمجموعة لازم له مجموعة، وأي نوع تاني بيصفّي الحقلين تلقائيًا
        $type = $data['type'] ?? $priceList?->type;
        $typeValue = $type instanceof PriceListType ? $type->value : $type;

        if ($typeValue === PriceListType::CustomerSpecific->value) {
            $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $priceList?->customer_id;
            if ($customerId === null) {
                throw new BusinessException('قائمة الأسعار الخاصة بعميل تتطلب تحديد العميل.');
            }
            $data['customer_id'] = $customerId;
            $data['customer_group_id'] = null;
        } elseif ($typeValue === PriceListType::CustomerGroup->value) {
            $groupId = array_key_exists('customer_group_id', $data) ? $data['customer_group_id'] : $priceList?->customer_group_id;
            if ($groupId === null) {
                throw new BusinessException('قائمة أسعار المجموعة تتطلب تحديد مجموعة العملاء.');
            }
            $data['customer_group_id'] = $groupId;
            $data['customer_id'] = null;
        } else {
            $data['customer_id'] = null;
            $data['customer_group_id'] = null;
        }

        return $data;
    }

    public function delete(PriceList $priceList, ?User $user = null): void
    {
        $this->assertListAccess($user, $priceList);
        $priceList->delete(); // العناصر بتتحذف بـ cascade في القاعدة
    }

    // ---------- Items ----------

    public function paginateItems(PriceList $priceList, array $filters, ?User $user = null): LengthAwarePaginator
    {
        $this->assertListAccess($user, $priceList);

        return $priceList->items()
            ->with(['variant.product'])
            ->when($filters['product_variant_id'] ?? null, fn ($q, $v) => $q->where('product_variant_id', $v))
            ->orderBy('product_variant_id')
            ->orderBy('min_qty')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function createItem(PriceList $priceList, array $data, ?User $user = null): PriceListItem
    {
        $this->assertListAccess($user, $priceList);
        $data = $this->normalizeDates($data);

        return DB::transaction(function () use ($priceList, $data): PriceListItem {
            $priceList = PriceList::query()->lockForUpdate()->findOrFail($priceList->id);
            $data['min_qty'] = $data['min_qty'] ?? 1;
            $this->validateDates($data);
            $this->assertNoDuplicateTier($priceList, (int) $data['product_variant_id'], (float) $data['min_qty']);

            return $priceList->items()->create($data)->load('variant.product');
        });
    }

    public function updateItem(PriceListItem $item, array $data, ?User $user = null): PriceListItem
    {
        $this->assertListAccess($user, $item->priceList);
        $data = $this->normalizeDates($data);

        return DB::transaction(function () use ($item, $data): PriceListItem {
            PriceList::query()->lockForUpdate()->findOrFail($item->price_list_id);
            $item = PriceListItem::query()->lockForUpdate()->findOrFail($item->id);
            $this->validateDates($data, $item);
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
        });
    }

    public function deleteItem(PriceListItem $item, ?User $user = null): void
    {
        $this->assertListAccess($user, $item->priceList);
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
    public function exportCsv(PriceList $priceList, ?User $user = null): StreamedResponse
    {
        $this->assertListAccess($user, $priceList);

        return response()->streamDownload(function () use ($priceList): void {
            $handle = fopen('php://output', 'w');
            try {
                fputcsv($handle, ['sku', 'variant_id', 'product_name', 'min_qty', 'price', 'starts_at', 'ends_at']);

                $priceList->items()
                    ->with(['variant.product' => fn ($query) => $query->withTrashed()])
                    ->chunkById(500, function ($items) use ($handle): void {
                        foreach ($items as $item) {
                            fputcsv($handle, [
                                $this->csvText($item->variant?->sku ?? ''),
                                $item->product_variant_id,
                                $this->csvText($item->variant?->product?->name_ar ?? ''),
                                $item->min_qty,
                                $item->price,
                                $item->starts_at?->toIso8601String(),
                                $item->ends_at?->toIso8601String(),
                            ]);
                        }
                    });

            } finally {
                fclose($handle);
            }
        }, 'price-list-'.$priceList->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvText(string $value): string
    {
        return preg_match('/^[\s]*[=+@\-\t\r\n]/u', $value) ? "'".$value : $value;
    }

    /**
     * استيراد CSV: كل صف بيحدّث أو ينشئ عنصرًا بمفتاح (القائمة، المتغير، min_qty)
     * داخل transaction واحدة. أخطاء الصفوف الفردية بتتجمع في التقرير من غير ما توقف الكل.
     *
     * @return array{created: int, updated: int, skipped: int, errors: list<array{line: int, message: string}>}
     */
    public function importCsv(PriceList $priceList, UploadedFile $file, ?User $user = null): array
    {
        $this->assertListAccess($user, $priceList);
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new BusinessException('تعذرت قراءة ملف الاستيراد.');
        }
        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new BusinessException('ملف الاستيراد فارغ.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $columns = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
            $index = array_flip($columns);
            if (count($index) !== count($columns) || ! isset($index['price']) || (! isset($index['variant_id']) && ! isset($index['sku']))) {
                throw new BusinessException('يجب أن يحتوي الملف على price وsku أو variant_id، دون أعمدة مكررة.');
            }
            $rows = [];
            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 10000) {
                    throw new BusinessException('عدد أسطر الملف يتجاوز الحد المسموح.');
                }
                if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                    continue;
                }
                if (count($rows) >= 2000) {
                    throw new BusinessException('الحد الأقصى للاستيراد المتزامن 2000 صف؛ قسّم الملف إلى أجزاء أصغر.');
                }
                $get = fn (string $key) => isset($index[$key]) ? trim((string) ($row[$index[$key]] ?? '')) : '';
                $rows[] = ['line' => $line, 'variant_id' => $get('variant_id'), 'sku' => $get('sku'),
                    'price' => $get('price'), 'min_qty' => $get('min_qty') === '' ? '1' : $get('min_qty'),
                    'starts_at' => $get('starts_at') === '' ? null : $get('starts_at'),
                    'ends_at' => $get('ends_at') === '' ? null : $get('ends_at')];
            }
        } finally {
            fclose($handle);
        }

        $ids = array_values(array_filter(array_map(fn ($row) => filter_var($row['variant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), $rows)));
        $skus = [];
        foreach ($rows as $row) {
            $skus[] = $row['sku'];
            $skus[] = $this->unescapeCsvText($row['sku']);
        }
        $variants = ProductVariant::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $ids)->orWhereIn('sku', array_unique($skus)))->get();
        $byId = $variants->keyBy('id');
        $bySku = $variants->keyBy('sku');
        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $valid = [];
        foreach ($rows as $row) {
            try {
                $variant = $row['variant_id'] !== ''
                    ? $byId->get(filter_var($row['variant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0)
                    : ($bySku->get($row['sku']) ?? $bySku->get($this->unescapeCsvText($row['sku'])));
                if ($variant === null || ($row['sku'] !== '' && $row['sku'] !== $variant->sku && $row['sku'] !== $this->csvText($variant->sku))) {
                    throw new BusinessException('الصنف غير موجود أو يوجد تعارض بين sku وvariant_id.');
                }
                $validator = Validator::make($row, [
                    'price' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
                    'min_qty' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999.9999'],
                    'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
                ]);
                if ($validator->fails()) {
                    throw new BusinessException($validator->errors()->first());
                }
                $row['starts_at'] = $this->parseDate($row['starts_at'] ?? '');
                $row['ends_at'] = $this->parseDate($row['ends_at'] ?? '');
                $this->validateDates($row);
                $valid[] = [...$row, 'product_variant_id' => $variant->id];
            } catch (BusinessException $exception) {
                $report['skipped']++;
                $report['errors'][] = ['line' => $row['line'], 'message' => $exception->getMessage()];
            }
        }
        DB::transaction(function () use ($valid, $priceList, &$report): void {
            PriceList::query()->lockForUpdate()->findOrFail($priceList->id);
            foreach ($valid as $row) {
                $item = PriceListItem::query()->updateOrCreate([
                    'price_list_id' => $priceList->id, 'product_variant_id' => $row['product_variant_id'], 'min_qty' => $row['min_qty'],
                ], ['price' => $row['price'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at']]);
                $item->wasRecentlyCreated ? $report['created']++ : $report['updated']++;
            }
        });

        return $report;
    }

    private function unescapeCsvText(string $value): string
    {
        return str_starts_with($value, "'") && $this->csvText(substr($value, 1)) === $value ? substr($value, 1) : $value;
    }

    /**
     * تحليل صارم للتواريخ: Y-m-d أو Y-m-d H:i:s أو ISO-8601 مع إزاحة فقط.
     * القيم بلا إزاحة تُفسَّر بمنطقة عمل (business_timezone) وتُخزَّن UTC —
     * لا strtotime: لا "next monday" ولا خلط بين التنسيقين الأمريكي والأوروبي.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $businessTz = (string) config('app.business_timezone', 'Africa/Cairo');
        foreach (['Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::rawCreateFromFormat($format, $value, $businessTz);
            } catch (\Throwable) {
                $parsed = false;
            }
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->utc()->format('Y-m-d H:i:s');
            }
        }

        throw new BusinessException("تاريخ غير صالح: {$value} — الصيغ المقبولة: Y-m-d أو Y-m-d H:i:s أو ISO-8601 مع إزاحة.");
    }

    /** تطبيع starts_at/ends_at القادمة من الـ API لنفس قواعد parseDate قبل الحفظ. */
    private function normalizeDates(array $data): array
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }
            $data[$field] = $data[$field] instanceof DateTimeInterface
                ? Carbon::instance($data[$field])->utc()->format('Y-m-d H:i:s')
                : $this->parseDate((string) $data[$field]);
        }

        return $data;
    }

    // ----- نطاق الفرع (M4): الموظف المقيد لا يرى/يعدّل إلا قوائم فرعه -----
    // مجموعات العملاء كيان مركزي بلا فرع بالتصميم، فلا ينطبق عليها هذا التقييد.

    private function restrictedBranch(?User $user): ?int
    {
        return $user === null ? null : app(BranchAccess::class)->restrictedBranch($user);
    }

    public function assertListAccess(?User $user, PriceList $priceList): void
    {
        $restricted = $this->restrictedBranch($user);
        if ($restricted !== null && $priceList->branch_id !== $restricted) {
            throw new AuthorizationException('قائمة الأسعار هذه خارج نطاق فرعك.');
        }
    }

    private function validateDates(array $data, PriceList|PriceListItem|null $model = null): void
    {
        $start = array_key_exists('starts_at', $data) ? $data['starts_at'] : $model?->starts_at;
        $end = array_key_exists('ends_at', $data) ? $data['ends_at'] : $model?->ends_at;
        if ($start !== null && $end !== null && Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw new BusinessException('نهاية صلاحية السعر يجب ألا تسبق بدايتها.');
        }
    }
}
