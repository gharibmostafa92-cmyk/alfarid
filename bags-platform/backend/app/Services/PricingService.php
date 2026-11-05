<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

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
    private array $catalogContexts = [];

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

        return $this->catalogContexts[$customer->id] ??= $this->resolveCatalogContext($customer);
    }

    /**
     * Builds the unique catalog pricing context key for an approved customer.
     */
    private function resolveCatalogContext(Customer $customer): array
    {
        $customer->loadMissing('customerGroup');
        $group = $customer->customerGroup?->is_active ? $customer->customerGroup : null;
        $specific = PriceList::query()->where('type', PriceListType::CustomerSpecific)->where('customer_id', $customer->id)->exists();
        $channel = $this->channelFor($customer);
        $public = $channel === PriceListType::Retail && $group === null && ! $specific;

        return ['key' => $public ? 'retail' : $channel->value.':group:'.($group?->id ?? 'none').($specific ? ':customer:'.$customer->id : ''), 'public_retail' => $public];
    }

    /**
     * F17: preload the per-customer pricing context once per listing and pass
     * it down — resolve()/tiersFor() otherwise re-query the customer group on
     * every variant (15 products x variants x calls per page).
     *
     * @return array{channel: PriceListType, eligible: bool, group: object|null}
     */
    public function contextFor(?Customer $customer, ?array $variantIds = null): array
    {
        $eligible = $customer !== null && $customer->status === CustomerStatus::Approved;
        if ($eligible) {
            $customer->loadMissing('customerGroup');
        }
        $group = $eligible && $customer->customerGroup?->is_active ? $customer->customerGroup : null;

        $channel = $this->channelFor($customer);
        $context = ['channel' => $channel, 'eligible' => $eligible, 'group' => $group];
        if ($variantIds !== null) {
            $context['items'] = $this->eligibleItems(null, PHP_FLOAT_MAX, null, Carbon::now(), $variantIds)
                ->where(function ($q) use ($channel, $eligible, $customer, $group): void {
                    $q->whereIn('price_lists.type', array_unique([$channel->value, PriceListType::Retail->value]));
                    if ($eligible) {
                        $q->orWhere(fn ($owner) => $owner->where('price_lists.type', PriceListType::CustomerSpecific)
                            ->where('price_lists.customer_id', $customer->id));
                    }
                    if ($group !== null) {
                        $q->orWhere(fn ($owner) => $owner->where('price_lists.type', PriceListType::CustomerGroup)
                            ->where('price_lists.customer_group_id', $group->id));
                    }
                })
                ->addSelect('price_lists.type as pricing_type', 'price_lists.branch_id as pricing_branch_id', 'price_lists.priority as pricing_priority')
                ->get()->groupBy('product_variant_id');
        }

        return $context;
    }

    /** Resolve storefront prices in SQL using the same owner, tier and priority rules as resolve(). */
    public function withStorePrice(Builder $variants, ?Customer $customer): Builder
    {
        $eligible = $customer !== null && $customer->status === CustomerStatus::Approved;
        $channel = $this->channelFor($customer);
        $group = $eligible ? $customer->customerGroup : null;
        $group = $group?->is_active ? $group : null;
        $factor = $this->discountFactor($group?->discount_percentage ?? '0');
        $items = $this->eligibleItems(null, 1, null, Carbon::now())
            ->where(function ($q) use ($eligible, $customer, $group, $channel): void {
                $q->whereIn('price_lists.type', array_unique([$channel->value, PriceListType::Retail->value]));
                if ($eligible) {
                    $q->orWhere(fn ($q) => $q->where('price_lists.type', PriceListType::CustomerSpecific)
                        ->where('price_lists.customer_id', $customer->id));
                }
                if ($group !== null) {
                    $q->orWhere(fn ($q) => $q->where('price_lists.type', PriceListType::CustomerGroup)
                        ->where('price_lists.customer_group_id', $group->id));
                }
            })
            ->select([])
            ->selectRaw("price_list_items.price * CASE WHEN price_lists.type IN ('customer_specific', 'customer_group') THEN 1::numeric ELSE ?::numeric END", [$factor])
            ->orderByRaw("CASE WHEN price_lists.type = 'customer_specific' THEN 0 WHEN price_lists.type = 'customer_group' THEN 1 WHEN price_lists.type = ? THEN 2 ELSE 3 END", [$channel->value]);
        $items = $this->ordered($items)->limit(1);

        // M6: mirror basePrice() in SQL — the wholesale channel falls back to
        // wholesale_price first, then retail_price.
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
     * @param  array{channel: PriceListType, eligible: bool, group: object|null, items?: \Illuminate\Support\Collection}|null  $ctx  Preloaded pricing context.
     * @return array{unit_price: float, base_price: float, source: string, price_list_id: int|null}
     * @throws BusinessException If quantity is non-positive or item has no valid price.
     */
    public function resolve(
        ProductVariant $variant,
        ?Customer $customer = null,
        float $qty = 1,
        ?int $branchId = null,
        ?array $ctx = null,
    ): array {
        if (! is_finite($qty) || $qty <= 0) {
            throw new BusinessException('كمية التسعير يجب أن تكون موجبة.');
        }
        $now = Carbon::now();

        // F17: reuse the preloaded per-customer context when the caller passes
        // it (wholesale listing); otherwise load it for this single call.
        $ctx ??= $this->contextFor($customer);
        $baseQuery = $this->eligibleItems($variant, $qty, $branchId, $now);
        $channel = $ctx['channel'];
        $eligibleCustomer = $ctx['eligible'];
        $pick = function (PriceListType $type, ?string $ownerField = null, ?int $ownerId = null) use ($ctx, $variant, $qty, $baseQuery, $branchId): ?PriceListItem {
            if (isset($ctx['items']) && $branchId === null) {
                return ($ctx['items'][$variant->id] ?? collect())
                    ->filter(fn ($item) => $item->pricing_type === $type->value && (float) $item->min_qty <= $qty)
                    ->sort(function ($a, $b): int {
                        return (($a->pricing_branch_id === null) <=> ($b->pricing_branch_id === null))
                            ?: ((float) $b->min_qty <=> (float) $a->min_qty)
                            ?: ((int) $b->pricing_priority <=> (int) $a->pricing_priority)
                            ?: BigDecimal::of($a->price)->compareTo(BigDecimal::of($b->price))
                            ?: ((int) $a->id <=> (int) $b->id);
                    })->first();
            }

            return $this->ordered((clone $baseQuery)->where('price_lists.type', $type)
                ->when($ownerField !== null, fn ($q) => $q->where('price_lists.'.$ownerField, $ownerId)))->first();
        };

        // Priority 1: Customer-specific pricing.
        if ($eligibleCustomer) {
            $item = $pick(PriceListType::CustomerSpecific, 'customer_id', $customer->id);

            if ($item !== null) {
                return $this->formatResponse($item->price, PriceListType::CustomerSpecific->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // Priority 2: Customer group pricing.
        $group = $ctx['group'];
        if ($group !== null) {
            $item = $pick(PriceListType::CustomerGroup, 'customer_group_id', $group->id);

            if ($item !== null) {
                return $this->formatResponse($item->price, PriceListType::CustomerGroup->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // Priority 3: Customer channel price list (wholesale, distributor, or retail).
        $item = $pick($channel);

        // Wholesale/distributor channels without matched items fall back to active retail price lists.
        if ($item === null && $channel !== PriceListType::Retail) {
            $item = $pick(PriceListType::Retail);

            if ($item !== null) {
                $channel = PriceListType::Retail;
            }
        }

        if ($item !== null) {
            $channelPrice = (string) $item->price;
            $channelListId = (int) $item->price_list_id;
            $channelSource = $channel->value;
        } elseif ($this->basePrice($variant, $channel) === null) {
            // Priority 4 is impossible: unpriced item without matching price list or base price.
            throw new BusinessException('الصنف غير مُسعَّر: لا توجد قائمة أسعار مطابقة ولا سعر قطاعي للمتغير.');
        } else {
            // Priority 4: Channel base price when no price list matches.
            $channelPrice = $this->basePrice($variant, $channel);
            $channelListId = null;
            $channelSource = 'base_price';
        }

        // Apply customer group discount percentage on top of channel list/base price.
        if ($group !== null && (float) $group->discount_percentage > 0) {
            return $this->formatResponse(
                (string) BigDecimal::of($channelPrice)->multipliedBy($this->discountFactor($group->discount_percentage)),
                'customer_group_discount',
                $channelListId,
                $variant,
                $channel,
            );
        }

        return $this->formatResponse($channelPrice, $channelSource, $channelListId, $variant, $channel);
    }

    /**
     * Resolves the effective unit price with a per-layer explanation waterfall.
     *
     * @return array{final: array, layers: list<array>, notes: list<string>}
     */
    public function trace(ProductVariant $variant, ?Customer $customer = null, float $qty = 1, ?int $branchId = null, ?array $ctx = null): array
    {
        if (! is_finite($qty) || $qty <= 0) {
            throw new BusinessException('كمية التسعير يجب أن تكون موجبة.');
        }
        $now = Carbon::now();
        $ctx ??= $this->contextFor($customer);
        $baseQuery = $this->eligibleItems($variant, $qty, $branchId, $now);
        $channel = $ctx['channel'];
        $eligibleCustomer = $ctx['eligible'];
        $group = $ctx['group'];
        $layers = [];
        $notes = [];

        if ($customer !== null
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
            && $customer->status !== CustomerStatus::Approved) {
            $notes[] = 'العميل جملة لكنه مش معتمد، فاتسعّر قطاعي.';
        }

        $pick = function (PriceListType $type, ?string $ownerField = null, ?int $ownerId = null) use ($ctx, $variant, $qty, $baseQuery, $branchId): ?PriceListItem {
            if (isset($ctx['items']) && $branchId === null) {
                return ($ctx['items'][$variant->id] ?? collect())
                    ->filter(fn ($item) => $item->pricing_type === $type->value && (float) $item->min_qty <= $qty)
                    ->sort(function ($a, $b): int {
                        return (($a->pricing_branch_id === null) <=> ($b->pricing_branch_id === null))
                            ?: ((float) $b->min_qty <=> (float) $a->min_qty)
                            ?: ((int) $b->pricing_priority <=> (int) $a->pricing_priority)
                            ?: BigDecimal::of($a->price)->compareTo(BigDecimal::of($b->price))
                            ?: ((int) $a->id <=> (int) $b->id);
                    })->first();
            }

            return $this->ordered((clone $baseQuery)->where('price_lists.type', $type)
                ->when($ownerField !== null, fn ($q) => $q->where('price_lists.'.$ownerField, $ownerId)))->first();
        };

        $tieNote = function (PriceListType $type, ?string $ownerField, ?int $ownerId, ?PriceListItem $winner) use ($ctx, $variant, $qty, $baseQuery, $branchId, &$notes): void {
            if ($winner === null || (isset($ctx['items']) && $branchId === null)) {
                return;
            }
            $top = $this->ordered((clone $baseQuery)->where('price_lists.type', $type)
                ->when($ownerField !== null, fn ($q) => $q->where('price_lists.'.$ownerField, $ownerId)))
                ->limit(2)->get(['price_list_items.id', 'price_list_items.min_qty', 'price_list_items.price', 'price_lists.priority', 'price_lists.branch_id']);
            if ($top->count() === 2
                && (int) $top[0]->priority === (int) $top[1]->priority
                && (float) $top[0]->min_qty === (float) $top[1]->min_qty
                && ($top[0]->branch_id === null) === ($top[1]->branch_id === null)
                && (float) $top[0]->price !== (float) $top[1]->price) {
                $notes[] = 'فيه قائمتين بنفس الأولوية، فكسبت الأرخص.';
            }
        };

        $skippedRest = function (array $names) use (&$layers): void {
            foreach ($names as $n) {
                $layers[] = ['layer' => $n, 'status' => 'skipped', 'reason' => 'طبقة أعلى كسبت.'];
            }
        };

        if (! $eligibleCustomer) {
            $layers[] = ['layer' => 'customer_specific', 'status' => 'not_eligible', 'reason' => 'العميل مش معتمد أو زائر، فقوائم العملاء الخاصة مش بتتطبق.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
            if ($customer !== null) {
                $specificId = PriceList::query()->where('type', PriceListType::CustomerSpecific)->where('customer_id', $customer->id)->orderByDesc('id')->value('id');
                if ($specificId !== null) {
                    $notes[] = "عنده قائمة خاصة رقم {$specificId}، بس العميل مش approved، فالقائمة دي مش بتتطبق.";
                }
            }
        } else {
            $item = $pick(PriceListType::CustomerSpecific, 'customer_id', $customer->id);
            if ($item !== null) {
                $tieNote(PriceListType::CustomerSpecific, 'customer_id', $customer->id, $item);
                $layers[] = ['layer' => 'customer_specific', 'status' => 'won', 'reason' => 'قائمة العميل الخاصة كسبت.', 'price_list_id' => (int) $item->price_list_id, 'price' => (float) $item->price, 'min_qty' => (float) $item->min_qty];
                $skippedRest(['customer_group', 'channel', 'retail_fallback', 'base_price']);
                $layers[] = ['layer' => 'group_discount', 'status' => 'skipped', 'reason' => 'خصم المجموعة 10% ماتطبقش لأن السعر جه من قائمة المجموعة نفسها.'];
                $final = $this->formatResponse($item->price, PriceListType::CustomerSpecific->value, (int) $item->price_list_id, $variant, $channel);

                return ['final' => $final, 'layers' => $layers, 'notes' => $notes];
            }
            $layers[] = ['layer' => 'customer_specific', 'status' => 'no_match', 'reason' => 'مفيش بند مطابق في قائمة العميل الخاصة.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        }

        if ($group === null) {
            $layers[] = ['layer' => 'customer_group', 'status' => 'not_eligible', 'reason' => 'العميل مش في مجموعة نشطة، فقوائم المجموعات مش بتتطبق.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        } else {
            $item = $pick(PriceListType::CustomerGroup, 'customer_group_id', $group->id);
            if ($item !== null) {
                $tieNote(PriceListType::CustomerGroup, 'customer_group_id', $group->id, $item);
                $layers[] = ['layer' => 'customer_group', 'status' => 'won', 'reason' => 'قائمة المجموعة كسبت.', 'price_list_id' => (int) $item->price_list_id, 'price' => (float) $item->price, 'min_qty' => (float) $item->min_qty];
                $skippedRest(['channel', 'retail_fallback', 'base_price']);
                $layers[] = ['layer' => 'group_discount', 'status' => 'skipped', 'reason' => 'خصم المجموعة 10% ماتطبقش لأن السعر جه من قائمة المجموعة نفسها.'];
                $final = $this->formatResponse($item->price, PriceListType::CustomerGroup->value, (int) $item->price_list_id, $variant, $channel);

                return ['final' => $final, 'layers' => $layers, 'notes' => $notes];
            }
            $layers[] = ['layer' => 'customer_group', 'status' => 'no_match', 'reason' => 'مفيش بند مطابق في قائمة المجموعة.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        }

        $item = $pick($channel);
        $channelWon = false;
        $channelPrice = null;
        $channelListId = null;
        $channelSource = $channel->value;
        if ($item !== null) {
            $tieNote($channel, null, null, $item);
            $channelWon = true;
            $channelPrice = (string) $item->price;
            $channelListId = (int) $item->price_list_id;
            $layers[] = ['layer' => 'channel', 'status' => 'won', 'reason' => "قائمة القناة ({$channel->value}) كسبت.", 'price_list_id' => $channelListId, 'price' => (float) $item->price, 'min_qty' => (float) $item->min_qty];
        } else {
            $layers[] = ['layer' => 'channel', 'status' => 'no_match', 'reason' => "مفيش بند مطابق في قائمة القناة ({$channel->value}).", 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        }

        if (! $channelWon && $channel !== PriceListType::Retail) {
            $retailItem = $pick(PriceListType::Retail);
            if ($retailItem !== null) {
                $tieNote(PriceListType::Retail, null, null, $retailItem);
                $channel = PriceListType::Retail;
                $channelWon = true;
                $channelPrice = (string) $retailItem->price;
                $channelListId = (int) $retailItem->price_list_id;
                $channelSource = $channel->value;
                $layers[] = ['layer' => 'retail_fallback', 'status' => 'won', 'reason' => 'القناة وقعت على قائمة قطاعي بديلة.', 'price_list_id' => $channelListId, 'price' => (float) $retailItem->price, 'min_qty' => (float) $retailItem->min_qty];
            } else {
                $layers[] = ['layer' => 'retail_fallback', 'status' => 'no_match', 'reason' => 'مفيش بند قطاعي بديل.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
            }
        } else {
            $layers[] = ['layer' => 'retail_fallback', 'status' => 'skipped', 'reason' => $channelWon ? 'القناة كسبت، فمش محتاج بديل قطاعي.' : 'القناة قطاعي بالفعل.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        }

        if (! $channelWon) {
            $base = $this->basePrice($variant, $channel);
            if ($base === null) {
                throw new BusinessException('الصنف غير مُسعَّر: لا توجد قائمة أسعار مطابقة ولا سعر قطاعي للمتغير.');
            }
            $channelPrice = $base;
            $channelListId = null;
            $channelSource = 'base_price';
            $layers[] = ['layer' => 'base_price', 'status' => 'won', 'reason' => 'مفيش قوائم مطابقة، فاتسعّر بالسعر الأساسي.', 'price_list_id' => null, 'price' => (float) $base, 'min_qty' => null];
        } else {
            $layers[] = ['layer' => 'base_price', 'status' => 'skipped', 'reason' => 'قائمة كسبت، فالسعر الأساسي مش مستخدم.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        }

        if ($group !== null && (float) $group->discount_percentage > 0) {
            $layers[] = ['layer' => 'group_discount', 'status' => 'won', 'reason' => "خصم المجموعة {$group->discount_percentage}% اتطبق على سعر القناة/الأساسي.", 'price_list_id' => $channelListId, 'price' => null, 'min_qty' => null];
            $final = $this->formatResponse(
                (string) BigDecimal::of($channelPrice)->multipliedBy($this->discountFactor($group->discount_percentage)),
                'customer_group_discount',
                $channelListId,
                $variant,
                $channel,
            );

            return ['final' => $final, 'layers' => $layers, 'notes' => $notes];
        }

        $layers[] = ['layer' => 'group_discount', 'status' => 'skipped', 'reason' => 'مفيش خصم مجموعة يتطبق.', 'price_list_id' => null, 'price' => null, 'min_qty' => null];
        $final = $this->formatResponse($channelPrice, $channelSource, $channelListId, $variant, $channel);

        return ['final' => $final, 'layers' => $layers, 'notes' => $notes];
    }

    /**
     * Returns the step-ladder volume pricing tiers applicable to a customer for a variant.
     *
     * @return list<array{min_qty: float, unit_price: float}>}
     *
     * @return list<array{min_qty: float, unit_price: float}>
     */
    public function tiersFor(ProductVariant $variant, ?Customer $customer, ?array $ctx = null, ?int $branchId = null): array
    {
        $now = Carbon::now();
        // F17: reuse the preloaded per-customer context when available.
        $ctx ??= $this->contextFor($customer);
        $channel = $ctx['channel'];
        $eligible = $ctx['eligible'];
        $groupId = $ctx['group']?->id;

        $breakpoints = isset($ctx['items']) && $branchId === null
            ? ($ctx['items'][$variant->id] ?? collect())->pluck('min_qty')->map(fn ($v) => (float) $v)
            : $this->eligibleItems($variant, PHP_FLOAT_MAX, $branchId, $now)
                ->where(function ($q) use ($eligible, $customer, $groupId, $channel): void {
                    $q->where('price_lists.type', $channel->value)
                        ->orWhere('price_lists.type', PriceListType::Retail->value);
                    if ($eligible) {
                        $q->orWhere(fn ($q) => $q->where('price_lists.type', PriceListType::CustomerSpecific)
                            ->where('price_lists.customer_id', $customer->id));
                    }
                    if ($groupId !== null) {
                        $q->orWhere(fn ($q) => $q->where('price_lists.type', PriceListType::CustomerGroup)
                            ->where('price_lists.customer_group_id', $groupId));
                    }
                })
                ->pluck('price_list_items.min_qty')
                ->map(fn ($v) => (float) $v)
                ->unique()
                ->sort()
                ->values();

        $points = collect([1.0])->merge($breakpoints)->unique()->sort()->values();

        return $points
            ->map(fn (float $minQty) => [
                'min_qty' => $minQty,
                'unit_price' => $this->resolve($variant, $customer, max(1.0, $minQty), $branchId, $ctx)['unit_price'],
            ])
            ->values()
            ->all();
    }

    /**
     * Resolves the pricing channel enum: approved wholesale/distributor accounts use their channel; all others use Retail.
     */
    private function channelFor(?Customer $customer): PriceListType
    {
        if ($customer !== null
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
            && $customer->status === CustomerStatus::Approved) {
            return PriceListType::from($customer->type->value);
        }

        return PriceListType::Retail;
    }

    private function basePrice(ProductVariant $variant, PriceListType $channel): ?string
    {
        // M6: wholesale_price is the wholesale-channel fallback. Retail stays
        // on retail_price; wholesale/distributor prefer wholesale_price and
        // fall back to retail_price when it is missing.
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
}
