<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

/**
 * الطلب السريع لتجار الجملة (المرحلة 12.2): بحث جماعي بالأكواد مع أسعار
 * الشرائح والتوفر، واستيراد بنود إلى سلة التاجر الحالية، وإعادة طلب سابق —
 * كل الإضافات تمر عبر CartService فتُسعَّر بأسعار اليوم تلقائيًا.
 */
class WholesaleQuickOrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CartService $carts,
    ) {}

    /**
     * بحث بالأكواد (SKU أو باركود) مع الكميات: السعر عند كمية كل بند من
     * شرائحه السارية لهذا التاجر، والمتاح من كل الفروع.
     *
     * @param  list<array{sku?: string|null, barcode?: string|null, qty: float}>  $lines
     * @return list<array<string, mixed>>
     */
    public function lookup(Customer $customer, array $lines): array
    {
        $variants = $this->findVariants($lines);
        $availability = $this->availabilityFor($variants->pluck('id')->all());
        // F17: one context for the whole lookup, not one group query per line.
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->unique()->all());

        return collect($lines)->map(function (array $line) use ($customer, $variants, $availability, $ctx): array {
            $code = (string) ($line['sku'] ?? $line['barcode'] ?? '');
            $variant = $variants->get($code);
            $qty = (float) $line['qty'];

            if ($variant === null) {
                return [
                    'code' => $code, 'found' => false, 'qty' => $qty,
                    'available_qty' => 0.0, 'in_stock' => false,
                ];
            }

            $available = $availability[$variant->id] ?? 0.0;
            $unitPrice = $this->pricing->resolve($variant, $customer, $qty, null, $ctx)['unit_price'];

            return [
                'code' => $code,
                'found' => true,
                'product_variant_id' => $variant->id,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'product_name' => $variant->product?->name_ar,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $qty, 2),
                'tier_prices' => $this->pricing->tiersFor($variant, $customer, $ctx),
                'available_qty' => $available,
                'in_stock' => $available >= $qty,
            ];
        })->all();
    }

    /**
     * إضافة بنود (من ملف مستورد) إلى سلة التاجر: الأكواد غير المعروفة أو
     * غير النشطة أو التي لا يغطيها المتاح تُتخطى بتقرير سبب، والباقي يُضاف
     * عبر CartService (مدمجًا مع بنود السلة القائمة وبأسعار اليوم).
     *
     * @param  list<array{sku: string, qty: float}>  $rows
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>, cart: array<string, mixed>}
     */
    public function importToCart(Customer $customer, array $rows): array
    {
        // دمج الأكواد المكررة في الملف قبل أي فحص توفر
        $merged = [];
        foreach ($rows as $row) {
            $sku = trim((string) $row['sku']);
            if ($sku === '') {
                continue;
            }
            $merged[$sku] = ($merged[$sku] ?? 0.0) + (float) $row['qty'];
        }

        $lines = collect($merged)->map(fn (float $qty, string $sku) => ['sku' => $sku, 'qty' => $qty])->values()->all();
        $variants = $this->findVariants($lines);
        $availability = $this->availabilityFor($variants->pluck('id')->all());

        $cart = $this->carts->resolveCart($customer, null);
        $inCart = $cart->items()->pluck('qty', 'product_variant_id')->map(fn ($q) => (float) $q)->all();

        // F17: one context for the whole import, not one group query per line.
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->unique()->all());

        $added = [];
        $skipped = [];
        foreach ($lines as $line) {
            $variant = $variants->get($line['sku']);

            if ($variant === null) {
                $skipped[] = ['sku' => $line['sku'], 'qty' => $line['qty'], 'reason' => 'not_found_or_inactive'];

                continue;
            }

            $alreadyInCart = $inCart[$variant->id] ?? 0.0;
            if (($availability[$variant->id] ?? 0.0) < $alreadyInCart + $line['qty']) {
                $skipped[] = ['sku' => $line['sku'], 'qty' => $line['qty'], 'reason' => 'insufficient_stock'];

                continue;
            }

            $cart = $this->carts->addItem($cart, $customer, $variant->id, $line['qty']);
            $inCart[$variant->id] = $alreadyInCart + $line['qty'];
            $added[] = [
                'sku' => $variant->sku,
                'product_variant_id' => $variant->id,
                'qty' => $line['qty'],
                'unit_price' => $this->pricing->resolve($variant, $customer, $inCart[$variant->id], null, $ctx)['unit_price'],
            ];
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'cart' => $this->carts->present($cart, $customer),
        ];
    }

    /**
     * إعادة طلب سابق: نسخ بنوده إلى سلة التاجر الحالية بأسعار اليوم
     * (الإضافة عبر CartService تعيد التسعير تلقائيًا)، مع تخطي ما لم يعد
     * متاحًا أو نشطًا بتقرير.
     *
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>, cart: array<string, mixed>}
     */
    public function reorderToCart(Customer $customer, Order $order): array
    {
        if ($order->customer_id !== $customer->id) {
            throw new BusinessException('الطلب غير موجود أو لا يخص هذا التاجر.', [], 404);
        }

        $rows = $order->items()
            ->get()
            ->groupBy('product_variant_id')
            ->map(fn (Collection $items, int $variantId) => [
                'sku' => (string) ($items->first()->sku_snapshot ?? ''),
                'qty' => (float) $items->sum('qty'),
                'variant_id' => $variantId,
            ])
            ->values()
            ->all();

        // نفس مسار الاستيراد لكن بالمفتاح هو معرّف المتغير لا الـ SKU اللقطة
        $cart = $this->carts->resolveCart($customer, null);
        $inCart = $cart->items()->pluck('qty', 'product_variant_id')->map(fn ($q) => (float) $q)->all();
        $variants = ProductVariant::query()->with('product')
            ->whereIn('id', collect($rows)->pluck('variant_id')->all())
            ->get()->keyBy('id');
        $availability = $this->availabilityFor($variants->pluck('id')->all());
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->all());

        $added = [];
        $skipped = [];
        foreach ($rows as $row) {
            $variant = $variants->get($row['variant_id']);

            if ($variant === null || ! $variant->is_active || $variant->product?->status !== ProductStatus::Published) {
                $skipped[] = ['sku' => $row['sku'], 'qty' => $row['qty'], 'reason' => 'not_found_or_inactive'];

                continue;
            }

            $alreadyInCart = $inCart[$variant->id] ?? 0.0;
            if (($availability[$variant->id] ?? 0.0) < $alreadyInCart + $row['qty']) {
                $skipped[] = ['sku' => $row['sku'], 'qty' => $row['qty'], 'reason' => 'insufficient_stock'];

                continue;
            }

            $cart = $this->carts->addItem($cart, $customer, $variant->id, $row['qty']);
            $inCart[$variant->id] = $alreadyInCart + $row['qty'];
            $added[] = [
                'sku' => $variant->sku,
                'product_variant_id' => $variant->id,
                'qty' => $row['qty'],
                'unit_price' => $this->pricing->resolve($variant, $customer, $inCart[$variant->id], null, $ctx)['unit_price'],
            ];
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'cart' => $this->carts->present($cart, $customer),
        ];
    }

    /**
     * خريطة كود ← متغير نشط لمنتج منشور، بمفتاح الـ SKU والباركود معًا.
     *
     * @param  list<array{sku?: string|null, barcode?: string|null}>  $lines
     * @return Collection<string, ProductVariant>
     */
    private function findVariants(array $lines): Collection
    {
        $skus = collect($lines)->pluck('sku')->filter()->unique()->all();
        $barcodes = collect($lines)->pluck('barcode')->filter()->unique()->all();

        $variants = ProductVariant::query()->with('product')
            ->where('is_active', true)
            ->whereHas('product', fn ($q) => $q->where('status', 'published'))
            ->where(function ($q) use ($skus, $barcodes): void {
                if ($skus !== []) {
                    $q->orWhereIn('sku', $skus);
                }
                if ($barcodes !== []) {
                    $q->orWhereIn('barcode', $barcodes);
                }
            })
            ->get();

        $map = collect();
        foreach ($variants as $variant) {
            $map->put($variant->sku, $variant);
            if ($variant->barcode !== null && $variant->barcode !== '') {
                $map->put($variant->barcode, $variant);
            }
        }

        return $map;
    }

    /**
     * المتاح الكلي لكل متغير عبر كل الفروع (عمود available المولّد يُقرأ
     * كنص من Postgres فيُحوَّل float — اصطلاح المشروع).
     *
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    private function availabilityFor(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return StockLevel::query()
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(available) AS total_available')
            ->pluck('total_available', 'product_variant_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
