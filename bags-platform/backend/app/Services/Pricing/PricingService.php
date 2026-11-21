<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\Catalog\PriceListType;
use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Central pricing engine across storefront, wholesale portal, POS, and admin backoffice.
 *
 * Evaluation hierarchy:
 * 1. Customer-specific price list (customer_specific)
 * 2. Customer group price list (customer_group)
 * 3. Channel price list (wholesale/distributor for approved accounts, otherwise retail).
 *    Wholesale/distributor channels without a matching item fall back to active retail lists.
 * 4. Channel base price (wholesale_price for wholesale/distributor, then retail_price).
 *
 * Customer group discount percentages apply on top of tier 3/4 channel outcomes without
 * overriding customer-specific or group-specific price lists.
 * Within each tier: branch-specific list first, highest qualifying min_qty, highest priority, lowest price.
 */
class PricingService
{
    public const CATALOG_VERSION_KEY = 'pricing:catalog_version';

    private array $catalogContexts = [];

    public function catalogVersion(): string
    {
        $version = Cache::get(self::CATALOG_VERSION_KEY);
        if (! is_string($version) || $version === '') {
            $version = (string) Str::uuid();
            Cache::forever(self::CATALOG_VERSION_KEY, $version);
        }

        return $version;
    }

    public function bumpCatalogVersion(): void
    {
        Cache::forever(self::CATALOG_VERSION_KEY, (string) Str::uuid());
        $this->flushCatalogContexts();
    }

    /**
     * Clears in-memory catalog context cache (useful across queue jobs and octane requests).
     */
    public function flushCatalogContexts(): void
    {
        $this->catalogContexts = [];
    }

    /**
     * Resolves cache key and public visibility context for customer catalog listings.
     *
     * @return array{key: string, public_retail: bool}
     */
    public function catalogContext(?Customer $customer): array
    {
        if ($customer === null || $customer->status !== CustomerStatus::Approved) {
            return ['key' => 'retail', 'public_retail' => true];
        }

        $customer->loadMissing('customerGroup');
        $cacheKey = implode(':', [
            $this->catalogVersion(),
            $customer->id,
            $customer->customer_group_id ?? 'none',
            $customer->updated_at?->getTimestamp() ?? 0,
            $customer->customerGroup?->updated_at?->getTimestamp() ?? 0,
        ]);

        if (! isset($this->catalogContexts[$cacheKey])) {
            if (count($this->catalogContexts) >= 256) {
                $this->catalogContexts = array_slice($this->catalogContexts, -128, null, true);
            }
            $this->catalogContexts[$cacheKey] = $this->resolveCatalogContext($customer);
        }

        return $this->catalogContexts[$cacheKey];
    }

    /**
     * Builds the unique catalog pricing context key for an approved customer.
     */
    private function resolveCatalogContext(Customer $customer): array
    {
        ['group' => $group] = $this->resolveEligibleGroup($customer);
        $specific = PriceList::query()
            ->where('type', PriceListType::CustomerSpecific)
            ->where('customer_id', $customer->id)
            ->where('is_active', true)
            ->exists();
        $channel = $this->channelFor($customer);
        $public = $channel === PriceListType::Retail && $group === null && ! $specific;

        return [
            'key' => $public ? 'retail' : $channel->value.':group:'.($group?->id ?? 'none').($specific ? ':customer:'.$customer->id : ''),
            'public_retail' => $public,
        ];
    }

    /**
     * Preloads the per-customer pricing context once per listing and passes
     * it down — resolve()/tiersFor() otherwise re-query the customer group on
     * every variant (15 products x variants x calls per page).
     *
     * @return array{channel: PriceListType, eligible: bool, group: object|null}
     */
    public function contextFor(?Customer $customer, ?array $variantIds = null, ?int $branchId = null): array
    {
        ['eligible' => $eligible, 'group' => $group] = $this->resolveEligibleGroup($customer);
        $channel = $this->channelFor($customer);
        $context = ['channel' => $channel, 'eligible' => $eligible, 'group' => $group];

        if ($variantIds !== null) {
            $context['branch_id'] = $branchId;
            $items = $this->eligibleItems(null, PHP_FLOAT_MAX, $branchId, Carbon::now(), $variantIds);
            $context['items'] = $this->applyEligibleListFilter($items, $channel, $eligible, $customer, $group?->id)
                ->addSelect('price_lists.type as pricing_type', 'price_lists.branch_id as pricing_branch_id', 'price_lists.priority as pricing_priority')
                ->get()
                ->groupBy('product_variant_id');
        }

        return $context;
    }

    /** Resolve storefront prices in SQL using the same owner, tier and priority rules as resolve(). */
    public function withStorePrice(Builder $variants, ?Customer $customer): Builder
    {
        ['eligible' => $eligible, 'group' => $group] = $this->resolveEligibleGroup($customer);
        $channel = $this->channelFor($customer);
        $factor = $this->discountFactor($group?->discount_percentage ?? '0');

        $items = $this->eligibleItems(null, 1, null, Carbon::now());
        $this->applyEligibleListFilter($items, $channel, $eligible, $customer, $group?->id)
            ->select([])
            ->selectRaw("price_list_items.price * CASE WHEN price_lists.type IN ('customer_specific', 'customer_group') THEN 1::numeric ELSE ?::numeric END", [$factor])
            ->orderByRaw("CASE WHEN price_lists.type = 'customer_specific' THEN 0 WHEN price_lists.type = 'customer_group' THEN 1 WHEN price_lists.type = ? THEN 2 ELSE 3 END", [$channel->value]);
        $items = $this->ordered($items)->limit(1);

        // Mirror basePrice() hierarchy in SQL: wholesale channels fallback to wholesale_price before retail_price.
        $fallback = in_array($channel, [PriceListType::Wholesale, PriceListType::Distributor], true)
            ? 'COALESCE(product_variants.wholesale_price, product_variants.retail_price)'
            : 'product_variants.retail_price';

        // resolve() switches the basis to retail when a wholesale customer
        // falls back to a retail list. Preserve that effective channel in SQL.
        $baseItems = (clone $items)->select([])->selectRaw("COALESCE(CASE WHEN price_lists.type = 'retail' THEN product_variants.retail_price ELSE ".$fallback." END, price_list_items.price * CASE WHEN price_lists.type IN ('customer_specific', 'customer_group') THEN 1::numeric ELSE ?::numeric END)", [$factor]);

        return $variants->select('product_variants.*')
            ->selectRaw('ROUND(COALESCE(('.$items->toSql().'), '.$fallback.' * ?)::numeric, 2) AS store_price', [...$items->getBindings(), $factor])
            ->selectRaw('ROUND(COALESCE(('.$baseItems->toSql().'), '.$fallback.')::numeric, 2) AS store_base_price', $baseItems->getBindings());
    }

    /**
     * Resolves the effective unit price, base reference price, and pricing source for a variant.
     *
     * @param  float  $qty  Purchasing quantity used for volume tier matching.
     * @param  int|null  $branchId  Optional branch restriction for branch-specific price lists.
     * @param  array{channel: PriceListType, eligible: bool, group: object|null, items?: Collection}|null  $ctx  Preloaded pricing context.
     * @return array{unit_price: float, base_price: float, source: string, price_list_id: int|null}
     *
     * @throws BusinessException If quantity is non-positive or item has no valid price.
     */
    public function resolve(
        ProductVariant $variant,
        ?Customer $customer = null,
        float $qty = 1,
        ?int $branchId = null,
        ?array $ctx = null,
    ): array {
        $this->assertPositiveQty($qty);

        $now = Carbon::now();
        // Reuse preloaded customer pricing context if supplied by bulk callers to prevent redundant queries.
        $ctx ??= $this->contextFor($customer);
        $baseQuery = null;
        $getBaseQuery = function () use (&$baseQuery, $variant, $qty, $branchId, $now): Builder {
            return $baseQuery ??= $this->eligibleItems($variant, $qty, $branchId, $now);
        };

        $channel = $ctx['channel'];
        $group = $ctx['group'];
        $pick = fn (PriceListType $type, ?string $ownerField = null, ?int $ownerId = null): ?PriceListItem => $this->pickWinningItem(
            $type,
            $ownerField,
            $ownerId,
            $variant,
            $qty,
            $branchId,
            $getBaseQuery,
            $ctx,
        );

        // Priority 1: Customer-specific pricing.
        if ($ctx['eligible']) {
            $item = $pick(PriceListType::CustomerSpecific, 'customer_id', $customer->id);
            if ($item !== null) {
                return $this->formatResponse($item->price, PriceListType::CustomerSpecific->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // Priority 2: Customer group pricing.
        if ($group !== null) {
            $item = $pick(PriceListType::CustomerGroup, 'customer_group_id', $group->id);
            if ($item !== null) {
                return $this->formatResponse($item->price, PriceListType::CustomerGroup->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // Priority 3 & 4: Channel price list (with retail fallback for commercial channels) or base price.
        ['price' => $channelPrice, 'list_id' => $channelListId, 'source' => $channelSource, 'channel' => $effectiveChannel] = $this->resolveChannelOrBase(
            $variant,
            $channel,
            $pick,
        );

        return $this->finalizeWithGroupDiscount($channelPrice, $channelSource, $channelListId, $variant, $effectiveChannel, $group);
    }

    /**
     * Resolves the effective unit price with a per-layer explanation waterfall.
     *
     * @return array{final: array, layers: list<array>, notes: list<string>}
     */
    public function trace(ProductVariant $variant, ?Customer $customer = null, float $qty = 1, ?int $branchId = null, ?array $ctx = null): array
    {
        $this->assertPositiveQty($qty);

        $ctx ??= $this->contextFor($customer);
        $baseQuery = $this->eligibleItems($variant, $qty, $branchId, Carbon::now());
        $channel = $ctx['channel'];
        $group = $ctx['group'];
        $layers = [];
        $notes = [];

        if ($customer !== null && $customer->isCommercialTier() && $customer->status !== CustomerStatus::Approved) {
            $notes[] = 'العميل جملة لكنه مش معتمد، فاتسعّر قطاعي.';
        }

        $pick = fn (PriceListType $type, ?string $ownerField = null, ?int $ownerId = null): ?PriceListItem => $this->pickWinningItem(
            $type,
            $ownerField,
            $ownerId,
            $variant,
            $qty,
            $branchId,
            $baseQuery,
            $ctx,
        );

        if ($earlyResult = $this->traceCustomerSpecificLayer($customer, $ctx['eligible'], $variant, $channel, $pick, $baseQuery, $branchId, $ctx, $layers, $notes)) {
            return $earlyResult;
        }

        if ($earlyResult = $this->traceCustomerGroupLayer($group, $variant, $channel, $pick, $baseQuery, $branchId, $ctx, $layers, $notes)) {
            return $earlyResult;
        }

        ['price' => $channelPrice, 'list_id' => $channelListId, 'source' => $channelSource, 'channel' => $effectiveChannel] = $this->traceChannelAndFallbackLayers(
            $variant,
            $channel,
            $pick,
            $baseQuery,
            $branchId,
            $ctx,
            $layers,
            $notes,
        );

        if ($group !== null && (float) $group->discount_percentage > 0) {
            $layers[] = $this->traceLayer('group_discount', 'won', "خصم المجموعة {$group->discount_percentage}% اتطبق على سعر القناة/الأساسي.", priceListId: $channelListId);
        } else {
            $layers[] = $this->traceLayer('group_discount', 'skipped', 'مفيش خصم مجموعة يتطبق.');
        }

        $final = $this->finalizeWithGroupDiscount($channelPrice, $channelSource, $channelListId, $variant, $effectiveChannel, $group);

        return ['final' => $final, 'layers' => $layers, 'notes' => $notes];
    }

    /**
     * Returns the step-ladder volume pricing tiers applicable to a customer for a variant.
     *
     * @return list<array{min_qty: float, unit_price: float}>
     */
    public function tiersFor(ProductVariant $variant, ?Customer $customer, ?array $ctx = null, ?int $branchId = null): array
    {
        $now = Carbon::now();
        // Reuse preloaded customer pricing context when available to prevent redundant group queries.
        $ctx ??= $this->contextFor($customer);
        $channel = $ctx['channel'];
        $eligible = $ctx['eligible'];
        $groupId = $ctx['group']?->id;

        if (isset($ctx['items']) && $branchId === null) {
            $breakpoints = ($ctx['items'][$variant->id] ?? collect())->pluck('min_qty')->map(fn ($v) => (float) $v);
        } else {
            $itemsQuery = $this->eligibleItems($variant, PHP_FLOAT_MAX, $branchId, $now);
            $breakpoints = $this->applyEligibleListFilter($itemsQuery, $channel, $eligible, $customer, $groupId)
                ->pluck('price_list_items.min_qty')
                ->map(fn ($v) => (float) $v)
                ->unique()
                ->sort()
                ->values();
        }

        $points = collect([1.0])->merge($breakpoints)->unique()->sort()->values();

        return $points
            ->map(fn (float $minQty) => [
                'min_qty' => $minQty,
                'unit_price' => $this->resolve($variant, $customer, max(1.0, $minQty), $branchId, $ctx)['unit_price'],
            ])
            ->values()
            ->all();
    }

    private function assertPositiveQty(float $qty): void
    {
        if (! is_finite($qty) || $qty <= 0) {
            throw new BusinessException('كمية التسعير يجب أن تكون موجبة.');
        }
    }

    /**
     * Resolves customer eligibility and active customer group once per pricing evaluation.
     *
     * @return array{eligible: bool, group: object|null}
     */
    private function resolveEligibleGroup(?Customer $customer): array
    {
        $eligible = $customer !== null && $customer->status === CustomerStatus::Approved;
        if ($eligible) {
            $customer->loadMissing('customerGroup');
        }

        $group = $eligible && $customer->customerGroup?->is_active ? $customer->customerGroup : null;

        return ['eligible' => $eligible, 'group' => $group];
    }

    /**
     * Restricts a price list items query to lists visible to the customer's channel, account, or group.
     */
    private function applyEligibleListFilter(
        Builder $query,
        PriceListType $channel,
        bool $eligible,
        ?Customer $customer,
        ?int $groupId,
    ): Builder {
        return $query->where(function ($q) use ($channel, $eligible, $customer, $groupId): void {
            $q->whereIn('price_lists.type', array_unique([$channel->value, PriceListType::Retail->value]));
            if ($eligible && $customer !== null) {
                $q->orWhere(fn ($owner) => $owner->where('price_lists.type', PriceListType::CustomerSpecific)
                    ->where('price_lists.customer_id', $customer->id));
            }
            if ($groupId !== null) {
                $q->orWhere(fn ($owner) => $owner->where('price_lists.type', PriceListType::CustomerGroup)
                    ->where('price_lists.customer_group_id', $groupId));
            }
        });
    }

    /**
     * Resolves channel price list (with retail fallback for commercial channels) or channel base price.
     *
     * @return array{price: string, list_id: int|null, source: string, channel: PriceListType}
     *
     * @throws BusinessException If no matching price list or base price exists.
     */
    private function resolveChannelOrBase(ProductVariant $variant, PriceListType $channel, \Closure $pick): array
    {
        $item = $pick($channel);

        // Wholesale/distributor channels without matched items fall back to active retail price lists.
        if ($item === null && $channel !== PriceListType::Retail) {
            $item = $pick(PriceListType::Retail);
            if ($item !== null) {
                $channel = PriceListType::Retail;
            }
        }

        if ($item !== null) {
            return [
                'price' => (string) $item->price,
                'list_id' => (int) $item->price_list_id,
                'source' => $channel->value,
                'channel' => $channel,
            ];
        }

        $base = $this->basePrice($variant, $channel);
        if ($base === null) {
            throw new BusinessException('الصنف غير مُسعَّر: لا توجد قائمة أسعار مطابقة ولا سعر قطاعي للمتغير.');
        }

        return [
            'price' => $base,
            'list_id' => null,
            'source' => 'base_price',
            'channel' => $channel,
        ];
    }

    private function finalizeWithGroupDiscount(
        string $price,
        string $source,
        ?int $priceListId,
        ProductVariant $variant,
        PriceListType $channel,
        ?object $group,
    ): array {
        if ($group !== null && (float) $group->discount_percentage > 0) {
            $discounted = (string) BigDecimal::of($price)->multipliedBy($this->discountFactor($group->discount_percentage));

            return $this->formatResponse($discounted, 'customer_group_discount', $priceListId, $variant, $channel);
        }

        return $this->formatResponse($price, $source, $priceListId, $variant, $channel);
    }

    /**
     * Evaluates the customer-specific tier for trace(), returning an early trace payload when it wins.
     */
    private function traceCustomerSpecificLayer(
        ?Customer $customer,
        bool $eligibleCustomer,
        ProductVariant $variant,
        PriceListType $channel,
        \Closure $pick,
        Builder $baseQuery,
        ?int $branchId,
        array $ctx,
        array &$layers,
        array &$notes,
    ): ?array {
        if (! $eligibleCustomer) {
            $layers[] = $this->traceLayer('customer_specific', 'not_eligible', 'العميل مش معتمد أو زائر، فقوائم العملاء الخاصة مش بتتطبق.');
            if ($customer !== null) {
                $specificId = PriceList::query()
                    ->where('type', PriceListType::CustomerSpecific)
                    ->where('customer_id', $customer->id)
                    ->orderByDesc('id')
                    ->value('id');
                if ($specificId !== null) {
                    $notes[] = "عنده قائمة خاصة رقم {$specificId}، بس العميل مش approved، فالقائمة دي مش بتتطبق.";
                }
            }

            return null;
        }

        $item = $pick(PriceListType::CustomerSpecific, 'customer_id', $customer->id);
        if ($item === null) {
            $layers[] = $this->traceLayer('customer_specific', 'no_match', 'مفيش بند مطابق في قائمة العميل الخاصة.');

            return null;
        }

        $this->appendTieNote(PriceListType::CustomerSpecific, 'customer_id', $customer->id, $item, $ctx, $baseQuery, $branchId, $notes);
        $layers[] = $this->traceLayer('customer_specific', 'won', 'قائمة العميل الخاصة كسبت.', $item);
        $this->appendSkippedLayers($layers, ['customer_group', 'channel', 'retail_fallback', 'base_price']);
        $layers[] = ['layer' => 'group_discount', 'status' => 'skipped', 'reason' => 'خصم المجموعة ما اتطبقش لأن السعر جه من قائمة العميل الخاصة.'];

        return [
            'final' => $this->formatResponse($item->price, PriceListType::CustomerSpecific->value, (int) $item->price_list_id, $variant, $channel),
            'layers' => $layers,
            'notes' => $notes,
        ];
    }

    /**
     * Evaluates the customer-group tier for trace(), returning an early trace payload when it wins.
     */
    private function traceCustomerGroupLayer(
        ?object $group,
        ProductVariant $variant,
        PriceListType $channel,
        \Closure $pick,
        Builder $baseQuery,
        ?int $branchId,
        array $ctx,
        array &$layers,
        array &$notes,
    ): ?array {
        if ($group === null) {
            $layers[] = $this->traceLayer('customer_group', 'not_eligible', 'العميل مش في مجموعة نشطة، فقوائم المجموعات مش بتتطبق.');

            return null;
        }

        $item = $pick(PriceListType::CustomerGroup, 'customer_group_id', $group->id);
        if ($item === null) {
            $layers[] = $this->traceLayer('customer_group', 'no_match', 'مفيش بند مطابق في قائمة المجموعة.');

            return null;
        }

        $this->appendTieNote(PriceListType::CustomerGroup, 'customer_group_id', $group->id, $item, $ctx, $baseQuery, $branchId, $notes);
        $layers[] = $this->traceLayer('customer_group', 'won', 'قائمة المجموعة كسبت.', $item);
        $this->appendSkippedLayers($layers, ['channel', 'retail_fallback', 'base_price']);
        $layers[] = ['layer' => 'group_discount', 'status' => 'skipped', 'reason' => 'خصم المجموعة ما اتطبقش مرتين لأن السعر جه من قائمة المجموعة نفسها.'];

        return [
            'final' => $this->formatResponse($item->price, PriceListType::CustomerGroup->value, (int) $item->price_list_id, $variant, $channel),
            'layers' => $layers,
            'notes' => $notes,
        ];
    }

    /**
     * Evaluates channel, retail fallback, and base price layers for trace().
     *
     * @return array{price: string, list_id: int|null, source: string, channel: PriceListType}
     */
    private function traceChannelAndFallbackLayers(
        ProductVariant $variant,
        PriceListType $channel,
        \Closure $pick,
        Builder $baseQuery,
        ?int $branchId,
        array $ctx,
        array &$layers,
        array &$notes,
    ): array {
        $item = $pick($channel);
        if ($item !== null) {
            $this->appendTieNote($channel, null, null, $item, $ctx, $baseQuery, $branchId, $notes);
            $layers[] = $this->traceLayer('channel', 'won', "قائمة القناة ({$channel->value}) كسبت.", $item);
            $layers[] = $this->traceLayer('retail_fallback', 'skipped', 'القناة كسبت، فمش محتاج بديل قطاعي.');
            $layers[] = $this->traceLayer('base_price', 'skipped', 'قائمة كسبت، فالسعر الأساسي مش مستخدم.');

            return [
                'price' => (string) $item->price,
                'list_id' => (int) $item->price_list_id,
                'source' => $channel->value,
                'channel' => $channel,
            ];
        }

        $layers[] = $this->traceLayer('channel', 'no_match', "مفيش بند مطابق في قائمة القناة ({$channel->value}).");

        if ($channel !== PriceListType::Retail) {
            $retailItem = $pick(PriceListType::Retail);
            if ($retailItem !== null) {
                $this->appendTieNote(PriceListType::Retail, null, null, $retailItem, $ctx, $baseQuery, $branchId, $notes);
                $layers[] = $this->traceLayer('retail_fallback', 'won', 'القناة وقعت على قائمة قطاعي بديلة.', $retailItem);
                $layers[] = $this->traceLayer('base_price', 'skipped', 'قائمة كسبت، فالسعر الأساسي مش مستخدم.');

                return [
                    'price' => (string) $retailItem->price,
                    'list_id' => (int) $retailItem->price_list_id,
                    'source' => PriceListType::Retail->value,
                    'channel' => PriceListType::Retail,
                ];
            }

            $layers[] = $this->traceLayer('retail_fallback', 'no_match', 'مفيش بند قطاعي بديل.');
        } else {
            $layers[] = $this->traceLayer('retail_fallback', 'skipped', 'القناة قطاعي بالفعل.');
        }

        $base = $this->basePrice($variant, $channel);
        if ($base === null) {
            throw new BusinessException('الصنف غير مُسعَّر: لا توجد قائمة أسعار مطابقة ولا سعر قطاعي للمتغير.');
        }

        $layers[] = $this->traceLayer('base_price', 'won', 'مفيش قوائم مطابقة، فاتسعّر بالسعر الأساسي.', price: (float) $base);

        return [
            'price' => $base,
            'list_id' => null,
            'source' => 'base_price',
            'channel' => $channel,
        ];
    }

    private function appendTieNote(
        PriceListType $type,
        ?string $ownerField,
        ?int $ownerId,
        ?PriceListItem $winner,
        array $ctx,
        Builder $baseQuery,
        ?int $branchId,
        array &$notes,
    ): void {
        if ($winner === null || (isset($ctx['items']) && $branchId === null)) {
            return;
        }

        $top = $this->ordered(
            (clone $baseQuery)
                ->where('price_lists.type', $type)
                ->when($ownerField !== null, fn ($q) => $q->where('price_lists.'.$ownerField, $ownerId))
        )
            ->limit(2)
            ->get(['price_list_items.id', 'price_list_items.min_qty', 'price_list_items.price', 'price_lists.priority', 'price_lists.branch_id']);

        if ($top->count() === 2
            && (int) $top[0]->priority === (int) $top[1]->priority
            && (float) $top[0]->min_qty === (float) $top[1]->min_qty
            && ($top[0]->branch_id === null) === ($top[1]->branch_id === null)
            && (float) $top[0]->price !== (float) $top[1]->price) {
            $notes[] = 'فيه قائمتين بنفس الأولوية، فكسبت الأرخص.';
        }
    }

    private function traceLayer(
        string $layer,
        string $status,
        string $reason,
        ?PriceListItem $item = null,
        ?int $priceListId = null,
        ?float $price = null,
    ): array {
        return [
            'layer' => $layer,
            'status' => $status,
            'reason' => $reason,
            'price_list_id' => $item !== null ? (int) $item->price_list_id : $priceListId,
            'price' => $item !== null ? (float) $item->price : $price,
            'min_qty' => $item !== null ? (float) $item->min_qty : null,
        ];
    }

    private function appendSkippedLayers(array &$layers, array $names): void
    {
        foreach ($names as $name) {
            $layers[] = ['layer' => $name, 'status' => 'skipped', 'reason' => 'طبقة أعلى كسبت.'];
        }
    }

    /**
     * Resolves the pricing channel enum: approved wholesale/distributor accounts use their channel; all others use Retail.
     */
    private function channelFor(?Customer $customer): PriceListType
    {
        if ($customer?->isWholesaleApproved()) {
            return PriceListType::from($customer->type->value);
        }

        return PriceListType::Retail;
    }

    private function basePrice(ProductVariant $variant, PriceListType $channel): ?string
    {
        // Wholesale channels prefer wholesale_price and fall back to retail_price when unassigned.
        if (in_array($channel, [PriceListType::Wholesale, PriceListType::Distributor], true)
            && $variant->wholesale_price !== null) {
            return (string) $variant->wholesale_price;
        }

        return $variant->retail_price === null ? null : $variant->base_price;
    }

    /**
     * Returns the base catalog reference price for a variant under the customer's channel.
     */
    public function basePriceForCustomer(ProductVariant $variant, ?Customer $customer): ?string
    {
        return $this->basePrice($variant, $this->channelFor($customer));
    }

    /**
     * Converts a percentage discount string (e.g. "15.00") into a decimal multiplication factor (0.85).
     */
    private function discountFactor(string $percentage): string
    {
        return (string) BigDecimal::of('100')->minus($percentage)->dividedBy('100', 8);
    }

    /**
     * Query scope for active price list items matching variant, quantity threshold, branch, and current time.
     */
    private function eligibleItems(?ProductVariant $variant, float $qty, ?int $branchId, Carbon $now, ?array $variantIds = null): Builder
    {
        return PriceListItem::query()
            ->select('price_list_items.*')
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->where('price_lists.is_active', true)
            ->when($variant !== null,
                fn ($q) => $q->where('price_list_items.product_variant_id', $variant->id),
                fn ($q) => $variantIds !== null
                    ? $q->whereIn('price_list_items.product_variant_id', $variantIds)
                    : $q->whereColumn('price_list_items.product_variant_id', 'product_variants.id'))
            ->where('price_list_items.min_qty', '<=', $qty)
            ->where(fn ($q) => $q->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.starts_at')->orWhere('price_list_items.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.ends_at')->orWhere('price_list_items.ends_at', '>=', $now))
            ->where(function ($q) use ($branchId): void {
                $q->whereNull('price_lists.branch_id');
                if ($branchId !== null) {
                    $q->orWhere('price_lists.branch_id', $branchId);
                }
            });
    }

    /**
     * Pick the winning price list item matching the specified type and ownership scope.
     */
    private function pickWinningItem(
        PriceListType $type,
        ?string $ownerField,
        ?int $ownerId,
        ProductVariant $variant,
        float $qty,
        ?int $branchId,
        \Closure|Builder $baseQuery,
        array $ctx,
    ): ?PriceListItem {
        if (isset($ctx['items']) && ($ctx['branch_id'] ?? null) === $branchId) {
            $winner = ($ctx['items'][$variant->id] ?? collect())
                ->filter(fn ($item) => $item->pricing_type === $type->value && (float) $item->min_qty <= $qty)
                ->sort(function ($a, $b): int {
                    return (($a->pricing_branch_id === null) <=> ($b->pricing_branch_id === null))
                        ?: ((float) $b->min_qty <=> (float) $a->min_qty)
                        ?: ((int) $b->pricing_priority <=> (int) $a->pricing_priority)
                        ?: BigDecimal::of($a->price)->compareTo(BigDecimal::of($b->price))
                        ?: ((int) $a->id <=> (int) $b->id);
                })->first();
            if ($winner !== null && isset($ctx['price_overrides'][$winner->id])) {
                $winner = clone $winner;
                $winner->price = $ctx['price_overrides'][$winner->id];
            }

            return $winner;
        }

        $query = $baseQuery instanceof \Closure ? $baseQuery() : $baseQuery;

        $winner = $this->ordered((clone $query)->where('price_lists.type', $type)
            ->when($ownerField !== null, fn ($q) => $q->where('price_lists.'.$ownerField, $ownerId)))->first();

        if ($winner !== null && isset($ctx['price_overrides'][$winner->id])) {
            $winner->price = $ctx['price_overrides'][$winner->id];
        }

        return $winner;
    }

    /**
     * Sorts candidate price list items: branch-specific first, highest min_qty, highest priority, lowest price.
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('price_lists.branch_id IS NULL ASC')
            ->orderByDesc('price_list_items.min_qty')
            ->orderByDesc('price_lists.priority')
            ->orderBy('price_list_items.price')->orderBy('price_list_items.id');
    }

    /**
     * Formats rounded pricing amounts and metadata into standard pricing response payload.
     *
     * @return array{unit_price: float, base_price: float, source: string, price_list_id: int|null}
     */
    private function formatResponse(string|float $unitPrice, string $source, ?int $priceListId, ProductVariant $variant, PriceListType $channel): array
    {
        return [
            'unit_price' => (float) (string) BigDecimal::of((string) $unitPrice)->toScale(2, RoundingMode::HalfUp),
            'base_price' => (float) (string) BigDecimal::of($this->basePrice($variant, $channel) ?? (string) $unitPrice)->toScale(2, RoundingMode::HalfUp),
            'source' => $source,
            'price_list_id' => $priceListId,
        ];
    }

    /**
     * Applies charm and psychological price rounding schemes.
     */
    public static function applyRounding(float $price, string $rounding): float
    {
        if ($price <= 0) {
            return round($price, 2);
        }

        return match ($rounding) {
            'nearest_5' => round($price / 5.0) * 5.0,
            'nearest_10' => round($price / 10.0) * 10.0,
            'end_with_9' => max(9.0, floor($price / 10.0) * 10.0 + 9.0),
            'end_with_99' => max(99.0, round($price / 100.0) * 100.0 - 1.0),
            default => round($price, 2),
        };
    }
}
