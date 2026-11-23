<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Enums\Catalog\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Services\Orders\CartService;
use App\Services\Pricing\PricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $found = $this->findVariants($lines);

        return $this->lookupLines($customer, $lines, $found['by_sku'], $found['by_barcode']);
    }

    /**
     * Preview already-resolved variants without matching their codes again.
     *
     * @param  list<array{product_variant_id: int, sku: string, qty: float}>  $lines
     * @return list<array<string, mixed>>
     */
    public function lookupSelectedVariants(Customer $customer, array $lines): array
    {
        $variants = $this->loadPurchasableVariantsById(
            array_column($lines, 'product_variant_id'),
            ['product.media', 'media'],
        );

        return $this->lookupLines($customer, $lines, $variants, $variants, byVariantId: true);
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
        $lines = $this->consolidateSkuRows($rows);
        $found = $this->findVariants($lines);
        $allIds = $found['all']->pluck('id')->unique()->all();
        $availability = $this->availabilityFor($allIds);
        // Preload pricing context for all candidate items in one batch to eliminate per-item overhead.
        $pricingCtx = $this->pricing->contextFor($customer, $allIds);

        return $this->addLinesToCart(
            $customer,
            $this->buildCandidateItemsFromCodes($lines, $found['by_sku'], $found['by_barcode']),
            $availability,
            $pricingCtx,
        );
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

        $rows = $order->items()->get()
            ->map(fn ($item): array => [
                'product_variant_id' => $item->product_variant_id,
                'sku' => (string) $item->sku_snapshot,
                'qty' => (float) $item->qty,
            ])
            ->all();

        return $this->importSelectedVariantsToCart($customer, $rows);
    }

    /**
     * Add already-resolved variants while rechecking stock and current prices.
     *
     * @param  list<array{product_variant_id: int, sku: string, qty: float}>  $rows
     * @return array{added: list<array<string, mixed>>, skipped: list<array<string, mixed>>, cart: array<string, mixed>}
     */
    public function importSelectedVariantsToCart(Customer $customer, array $rows): array
    {
        $lines = $this->consolidateVariantRows($rows);
        $variants = $this->loadPurchasableVariantsById(
            $lines->pluck('product_variant_id')->all(),
            ['product'],
        );
        $variantIds = $variants->pluck('id')->all();
        $availability = $this->availabilityFor($variantIds);
        $pricingCtx = $this->pricing->contextFor($customer, $variantIds);

        $items = $lines->map(fn (array $row): array => [
            'sku' => $row['sku'],
            'qty' => $row['qty'],
            'variant' => $variants->get($row['product_variant_id']),
        ])->all();

        return $this->addLinesToCart($customer, $items, $availability, $pricingCtx);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int|string, ProductVariant>  $bySku
     * @param  Collection<int|string, ProductVariant>  $byBarcode
     * @return list<array<string, mixed>>
     */
    private function lookupLines(
        Customer $customer,
        array $lines,
        Collection $bySku,
        Collection $byBarcode,
        bool $byVariantId = false,
    ): array {
        $allIds = $bySku->merge($byBarcode)->pluck('id')->unique()->all();
        $availability = $this->availabilityFor($allIds);
        // Preload pricing context for all looked-up variants in a single query batch.
        $pricingCtx = $this->pricing->contextFor($customer, $allIds);

        return collect($lines)
            ->map(function (array $line) use ($customer, $bySku, $byBarcode, $availability, $pricingCtx, $byVariantId): array {
                $variant = $this->resolveVariantForLine($line, $bySku, $byBarcode, $byVariantId);

                return $this->presentLookupLine($line, $variant, $customer, $availability, $pricingCtx);
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  Collection<int|string, ProductVariant>  $bySku
     * @param  Collection<int|string, ProductVariant>  $byBarcode
     */
    private function resolveVariantForLine(
        array $line,
        Collection $bySku,
        Collection $byBarcode,
        bool $byVariantId,
    ): ?ProductVariant {
        if ($byVariantId) {
            return $bySku->get($line['product_variant_id']);
        }

        if (isset($line['sku']) && trim((string) $line['sku']) !== '') {
            $key = $this->normalizeCode((string) $line['sku']);

            return $bySku->get($key) ?? $byBarcode->get($key);
        }

        $key = $this->normalizeCode((string) ($line['barcode'] ?? ''));

        return $byBarcode->get($key) ?? $bySku->get($key);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<int, float>  $availability
     * @param  array<string, mixed>  $pricingCtx
     * @return array<string, mixed>
     */
    private function presentLookupLine(
        array $line,
        ?ProductVariant $variant,
        Customer $customer,
        array $availability,
        array $pricingCtx,
    ): array {
        $code = (string) ($line['sku'] ?? $line['barcode'] ?? '');
        $qty = (float) $line['qty'];

        if ($variant === null) {
            return [
                'code' => $code,
                'found' => false,
                'qty' => $qty,
                'available_qty' => 0.0,
                'in_stock' => false,
            ];
        }

        $available = $availability[$variant->id] ?? 0.0;
        $unitPrice = $this->pricing->resolve($variant, $customer, $qty, null, $pricingCtx)['unit_price'];

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
            'tier_prices' => $this->pricing->tiersFor($variant, $customer, $pricingCtx),
            'available_qty' => $available,
            'in_stock' => $available >= $qty,
        ];
    }

    /**
     * Consolidate duplicate SKU codes within imported rows before stock availability checks.
     *
     * @param  list<array{sku: string, qty: float}>  $rows
     * @return list<array{sku: string, qty: float}>
     */
    private function consolidateSkuRows(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $sku = trim((string) $row['sku']);
            if ($sku === '') {
                continue;
            }
            $merged[$sku] = ($merged[$sku] ?? 0.0) + (float) $row['qty'];
        }

        return collect($merged)
            ->map(fn (float $qty, string $sku): array => ['sku' => $sku, 'qty' => $qty])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{product_variant_id: int, sku: string, qty: float}>  $rows
     * @return Collection<int, array{product_variant_id: int, sku: string, qty: float}>
     */
    private function consolidateVariantRows(array $rows): Collection
    {
        return collect($rows)
            ->groupBy('product_variant_id')
            ->map(fn (Collection $items, int $variantId): array => [
                'product_variant_id' => $variantId,
                'sku' => $items->first()['sku'],
                'qty' => (float) $items->sum('qty'),
            ])
            ->values();
    }

    /**
     * @param  list<array{sku: string, qty: float}>  $lines
     * @param  Collection<string, ProductVariant>  $bySku
     * @param  Collection<string, ProductVariant>  $byBarcode
     * @return list<array{sku: string, qty: float, variant: ?ProductVariant}>
     */
    private function buildCandidateItemsFromCodes(array $lines, Collection $bySku, Collection $byBarcode): array
    {
        return array_map(fn (array $line): array => [
            'sku' => $line['sku'],
            'qty' => $line['qty'],
            'variant' => $this->resolveVariantForLine($line, $bySku, $byBarcode, byVariantId: false),
        ], $lines);
    }

    /**
     * @param  list<int>  $variantIds
     * @param  list<string>  $relations
     * @return Collection<int, ProductVariant>
     */
    private function loadPurchasableVariantsById(array $variantIds, array $relations = []): Collection
    {
        return ProductVariant::query()
            ->with($relations)
            ->wholesalePurchasable()
            ->whereIn('id', $variantIds)
            ->get()
            ->keyBy('id');
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
        return DB::transaction(function () use ($customer, $items, $availability, $pricingCtx): array {
            $cart = $this->carts->resolveCart($customer, null);
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
            $inCart = $cart->items()->pluck('qty', 'product_variant_id')->map(fn ($q): float => (float) $q)->all();

            $added = [];
            $skipped = [];

            foreach ($items as $item) {
                [$cart, $addedLine, $skippedLine] = $this->processCartCandidate(
                    $cart,
                    $customer,
                    $item,
                    $availability,
                    $pricingCtx,
                    $inCart,
                );

                if ($addedLine !== null) {
                    $added[] = $addedLine;
                } elseif ($skippedLine !== null) {
                    $skipped[] = $skippedLine;
                }
            }

            return [
                'added' => $added,
                'skipped' => $skipped,
                'cart' => $this->carts->present($cart, $customer),
            ];
        });
    }

    /**
     * @param  array{sku: string, qty: float, variant: ?ProductVariant}  $item
     * @param  array<int, float>  $availability
     * @param  array<string, mixed>  $pricingCtx
     * @param  array<int, float>  $inCart
     * @return array{0: Cart, 1: ?array<string, mixed>, 2: ?array<string, mixed>}
     */
    private function processCartCandidate(
        Cart $cart,
        Customer $customer,
        array $item,
        array $availability,
        array $pricingCtx,
        array &$inCart,
    ): array {
        $variant = $item['variant'];
        $sku = $item['sku'];
        $qty = (float) $item['qty'];

        if (! $this->isVariantPurchasable($variant)) {
            return [$cart, null, ['sku' => $sku, 'qty' => $qty, 'reason' => 'not_found_or_inactive']];
        }

        $alreadyInCart = $inCart[$variant->id] ?? 0.0;
        if (($availability[$variant->id] ?? 0.0) < $alreadyInCart + $qty) {
            return [$cart, null, ['sku' => $sku, 'qty' => $qty, 'reason' => 'insufficient_stock']];
        }

        $cart = $this->carts->addItem($cart, $customer, $variant->id, $qty);
        $inCart[$variant->id] = $alreadyInCart + $qty;

        return [$cart, [
            'sku' => $variant->sku,
            'product_variant_id' => $variant->id,
            'qty' => $qty,
            'unit_price' => $this->pricing->resolve($variant, $customer, $inCart[$variant->id], null, $pricingCtx)['unit_price'],
        ], null];
    }

    private function isVariantPurchasable(?ProductVariant $variant): bool
    {
        return $variant !== null
            && $variant->is_active
            && $variant->product?->status === ProductStatus::Published;
    }

    /**
     * Map active variants of published products into separate SKU and barcode namespaces.
     *
     * @param  list<array{sku?: string|null, barcode?: string|null}>  $lines
     * @return array{by_sku: Collection<string, ProductVariant>, by_barcode: Collection<string, ProductVariant>, all: Collection<int, ProductVariant>}
     */
    private function findVariants(array $lines): array
    {
        $rawCodes = $this->extractUniqueCodes($lines);
        if ($rawCodes === []) {
            return ['by_sku' => collect(), 'by_barcode' => collect(), 'all' => collect()];
        }

        $variants = $this->queryVariantsByCodes($rawCodes);
        $indexed = $this->indexVariantsByCode($variants);

        return [
            'by_sku' => $indexed['by_sku'],
            'by_barcode' => $indexed['by_barcode'],
            'all' => $variants,
        ];
    }

    /**
     * @param  list<array{sku?: string|null, barcode?: string|null}>  $lines
     * @return list<string>
     */
    private function extractUniqueCodes(array $lines): array
    {
        return collect($lines)
            ->flatMap(fn (array $line): array => [$line['sku'] ?? null, $line['barcode'] ?? null])
            ->map(fn ($code): string => trim((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $rawCodes
     * @return Collection<int, ProductVariant>
     */
    private function queryVariantsByCodes(array $rawCodes): Collection
    {
        $upperCodes = array_values(array_unique(array_map($this->normalizeCode(...), $rawCodes)));
        $placeholders = implode(', ', array_fill(0, count($upperCodes), '?'));

        return ProductVariant::query()
            ->with(['product.media', 'media'])
            ->wholesalePurchasable()
            ->where(function ($query) use ($upperCodes, $rawCodes, $placeholders): void {
                $query->whereRaw("UPPER(sku) IN ({$placeholders})", $upperCodes)
                    ->orWhereIn('barcode', $rawCodes)
                    ->orWhereIn('barcode', $upperCodes);
            })
            ->get();
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return array{by_sku: Collection<string, ProductVariant>, by_barcode: Collection<string, ProductVariant>}
     */
    private function indexVariantsByCode(Collection $variants): array
    {
        $bySku = collect();
        $byBarcode = collect();

        foreach ($variants as $variant) {
            if ($variant->sku !== null && $variant->sku !== '') {
                $bySku->put($this->normalizeCode($variant->sku), $variant);
            }
            if ($variant->barcode !== null && $variant->barcode !== '') {
                $byBarcode->put($this->normalizeCode($variant->barcode), $variant);
            }
        }

        return ['by_sku' => $bySku, 'by_barcode' => $byBarcode];
    }

    private function normalizeCode(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
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
            ->join('branches', 'branches.id', '=', 'stock_levels.branch_id')
            ->where('branches.is_active', true)
            ->whereIn('stock_levels.product_variant_id', $variantIds)
            ->groupBy('stock_levels.product_variant_id')
            ->selectRaw('stock_levels.product_variant_id, SUM(stock_levels.available) AS total_available')
            ->pluck('total_available', 'stock_levels.product_variant_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }
}
