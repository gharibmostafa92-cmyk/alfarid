<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Services\Orders\CartService;
use App\Services\Pricing\PricingService;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

/**
 * Quick-order service for wholesale customers.
 * Handles bulk code lookups (with tiered pricing and stock availability), cart imports, and order re-ordering.
 * All cart additions route through CartService to guarantee current real-time pricing.
 */
class WholesaleQuickOrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CartService $carts,
    ) {}

    /**
     * Batch lookup product variants by SKU or barcode with quantity-tier pricing and total available stock.
     *
     * @param  Customer  $customer  Wholesale customer performing the lookup.
     * @param  list<array{sku?: string|null, barcode?: string|null, qty: float}>  $lines  Requested order lines.
     * @return list<array<string, mixed>> Line details with live pricing and availability status.
     */
    public function lookup(Customer $customer, array $lines): array
    {
        $variants = $this->findVariants($lines);
        $availability = $this->availabilityFor($variants->pluck('id')->all());
        // Preload pricing context for all looked-up variants in a single query batch.
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
                'label' => $variant->displayLabel(),
                'image' => $this->imageFor($variant),
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
     * Import multiple items from a parsed CSV/Excel file into the customer's active cart.
     * Skips invalid codes or lines exceeding available stock with explanatory status reports.
     *
     * @param  Customer  $customer  Target wholesale customer account.
     * @param  list<array{sku: string, qty: float}>  $rows  Parsed spreadsheet rows.
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>, cart: array<string, mixed>}
     */
    public function importToCart(Customer $customer, array $rows): array
    {
        // Consolidate duplicate codes within the input before running stock availability checks.
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
        // Preload pricing context for all candidate items in one batch to eliminate per-item overhead.
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->unique()->all());

        $items = array_map(fn (array $line) => [
            'sku' => $line['sku'],
            'qty' => $line['qty'],
            'variant' => $variants->get($line['sku']),
        ], $lines);

        return $this->addLinesToCart($customer, $items, $availability, $ctx);
    }

    /**
     * Copy lines from a previous order into the customer's current cart at today's real-time prices.
     * Skips items that are discontinued, unpublished, or out of stock.
     *
     * @param  Customer  $customer  Target wholesale customer account.
     * @param  Order  $order  Historical order to duplicate into cart.
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

        // Key by active variant ID rather than historical snapshot SKU to ensure integrity against catalog renames.
        $variants = ProductVariant::query()->with('product')
            ->whereIn('id', collect($rows)->pluck('variant_id')->all())
            ->get()->keyBy('id');
        $availability = $this->availabilityFor($variants->pluck('id')->all());
        $ctx = $this->pricing->contextFor($customer, $variants->pluck('id')->all());

        $items = array_map(fn (array $row) => [
            'sku' => $row['sku'],
            'qty' => $row['qty'],
            'variant' => $variants->get($row['variant_id']),
        ], $rows);

        return $this->addLinesToCart($customer, $items, $availability, $ctx);
    }

    /**
     * Iterates over candidate order lines, applying stock checks and adding available items to the active cart.
     *
     * @param  list<array{sku: string, qty: float, variant: ?ProductVariant}>  $items
     * @param  array<int, float>  $availability
     * @param  array<string, mixed>  $pricingCtx
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>, cart: array<string, mixed>}
     */
    private function addLinesToCart(Customer $customer, array $items, array $availability, array $pricingCtx): array
    {
        $cart = $this->carts->resolveCart($customer, null);
        $inCart = $cart->items()->pluck('qty', 'product_variant_id')->map(fn ($q) => (float) $q)->all();

        $added = [];
        $skipped = [];
        foreach ($items as $item) {
            $variant = $item['variant'];
            $sku = $item['sku'];
            $qty = (float) $item['qty'];

            if ($variant === null || ! $variant->is_active || $variant->product?->status !== ProductStatus::Published) {
                $skipped[] = ['sku' => $sku, 'qty' => $qty, 'reason' => 'not_found_or_inactive'];

                continue;
            }

            $alreadyInCart = $inCart[$variant->id] ?? 0.0;
            if (($availability[$variant->id] ?? 0.0) < $alreadyInCart + $qty) {
                $skipped[] = ['sku' => $sku, 'qty' => $qty, 'reason' => 'insufficient_stock'];

                continue;
            }

            $cart = $this->carts->addItem($cart, $customer, $variant->id, $qty);
            $inCart[$variant->id] = $alreadyInCart + $qty;
            $added[] = [
                'sku' => $variant->sku,
                'product_variant_id' => $variant->id,
                'qty' => $qty,
                'unit_price' => $this->pricing->resolve($variant, $customer, $inCart[$variant->id], null, $pricingCtx)['unit_price'],
            ];
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'cart' => $this->carts->present($cart, $customer),
        ];
    }

    /**
     * Map active variants of published products by SKU and barcode.
     *
     * @param  list<array{sku?: string|null, barcode?: string|null}>  $lines
     * @return Collection<string, ProductVariant>
     */
    private function findVariants(array $lines): Collection
    {
        $skus = collect($lines)->pluck('sku')->filter()->unique()->all();
        $barcodes = collect($lines)->pluck('barcode')->filter()->unique()->all();

        if ($skus === [] && $barcodes === []) {
            return collect();
        }

        $variants = ProductVariant::query()->with(['product.media', 'media'])
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
     * Resolves primary thumbnail following catalog priority (product gallery first, then variant media).
     */
    private function imageFor(ProductVariant $variant): ?string
    {
        $media = $variant->product?->getMedia('images')->first()
            ?? $variant->getMedia('images')->first();

        if ($media === null) {
            return null;
        }

        return $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl();
    }

    /**
     * Total available stock across all branches for a given list of variant IDs.
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
