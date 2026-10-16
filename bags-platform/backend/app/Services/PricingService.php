<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * محرك التسعير المركزي — يُستدعى من المتجر والجملة والـ POS ولوحة الإدارة.
 *
 * شجرة الأولويات:
 * 1. سعر خاص بالعميل (customer_specific)
 * 2. سعر مجموعة العميل (customer_group)
 * 3. قائمة النوع (wholesale/distributor للمعتمدين فقط، وإلا retail)؛ وقناة
 *    جملة/موزع بلا عنصر مطابق ترجع لقوائم retail النشطة قبل السعر الأساسي.
 * 4. السعر الأساسي للمتغير (base_price، المتوافق مع retail_price من المرحلة 2).
 *
 * خصم مجموعة العميل (discount_percentage) لا يصنع مستوى مستقلًا: يُطبق فوق ناتج
 * المستويين 3/4 (سعر القناة)، ولا يُخصم من سعر خاص بالعميل أو سعر قائمة المجموعة.
 * متغير بلا retail_price ولا قائمة مطابقة = غير مُسعَّر، ويرمي BusinessException.
 *
 * داخل كل مستوى: القائمة المخصصة للفرع أولًا، ثم أعلى شريحة كمية محققة،
 * ثم أولوية القائمة، ثم السعر الأقل.
 */
class PricingService
{
    /** Resolve storefront prices in SQL using the same owner, tier and priority rules as resolve(). */
    public function withStorePrice(Builder $variants, ?Customer $customer): Builder
    {
        $eligible = $customer !== null && $customer->status === CustomerStatus::Approved;
        $channel = $this->channelFor($customer);
        $group = $eligible ? $customer->customerGroup : null;
        $group = $group?->is_active ? $group : null;
        $factor = $group !== null ? 1 - (float) $group->discount_percentage / 100 : 1;
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

        return $variants->select('product_variants.*')
            ->selectRaw('ROUND(COALESCE(('.$items->toSql().'), product_variants.retail_price * ?)::numeric, 2) AS store_price', [...$items->getBindings(), $factor]);
    }

    /**
     * @return array{unit_price: float, base_price: float, source: string, price_list_id: int|null}
     */
    public function resolve(
        ProductVariant $variant,
        ?Customer $customer = null,
        float $qty = 1,
        ?int $branchId = null,
    ): array {
        if (! is_finite($qty) || $qty <= 0) {
            throw new BusinessException('كمية التسعير يجب أن تكون موجبة.');
        }
        $now = Carbon::now();

        $baseQuery = $this->eligibleItems($variant, $qty, $branchId, $now);
        $channel = $this->channelFor($customer);
        $eligibleCustomer = $customer !== null && $customer->status === CustomerStatus::Approved;

        // الأولوية 1: سعر خاص بالعميل
        if ($eligibleCustomer) {
            $item = $this->ordered((clone $baseQuery)
                ->where('price_lists.type', PriceListType::CustomerSpecific)
                ->where('price_lists.customer_id', $customer->id)
            )->first();

            if ($item !== null) {
                return $this->formatResponse((float) $item->price, PriceListType::CustomerSpecific->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // الأولوية 2: سعر مجموعة العميل
        $group = $eligibleCustomer ? $customer->customerGroup()->where('is_active', true)->first() : null;
        if ($group !== null) {
            $item = $this->ordered((clone $baseQuery)
                ->where('price_lists.type', PriceListType::CustomerGroup)
                ->where('price_lists.customer_group_id', $group->id)
            )->first();

            if ($item !== null) {
                return $this->formatResponse((float) $item->price, PriceListType::CustomerGroup->value, (int) $item->price_list_id, $variant, $channel);
            }
        }

        // الأولوية 3: قائمة نوع العميل (أو القطاعي افتراضيًا)
        $item = $this->ordered((clone $baseQuery)
            ->where('price_lists.type', $channel)
        )->first();

        // قناة جملة/موزع بلا عنصر في قائمتها ترجع لقوائم القطاعي النشطة قبل
        // السعر الأساسي — وإلا دفع عميل الجملة أكثر من عميل التجزئة (M-1).
        if ($item === null && $channel !== PriceListType::Retail) {
            $item = $this->ordered((clone $baseQuery)
                ->where('price_lists.type', PriceListType::Retail)
            )->first();

            if ($item !== null) {
                $channel = PriceListType::Retail;
            }
        }

        if ($item !== null) {
            $channelPrice = (float) $item->price;
            $channelListId = (int) $item->price_list_id;
            $channelSource = $channel->value;
        } elseif ($variant->retail_price === null) {
            // الأولوية 4 مستحيلة: لا قائمة مطابقة ولا سعر قطاعي — الصنف غير مُسعَّر
            // ولا يجوز أن يخرج بسعر صفر ليُباع مجانًا.
            throw new BusinessException('الصنف غير مُسعَّر: لا توجد قائمة أسعار مطابقة ولا سعر قطاعي للمتغير.');
        } else {
            // الأولوية 4: السعر الأساسي الموحد؛ أسعار الجملة والموزعين تأتي من القوائم.
            $channelPrice = $this->basePrice($variant, $channel);
            $channelListId = null;
            $channelSource = 'base_price';
        }

        // خصم مجموعة العميل يُطبق "فوق" سعر القناة المحسوب (قائمة القناة أو الأساسي)،
        // ولا يتجاوزه أبدًا — فلا يخسر العميل سعر جملة/موزع أفضل بسبب خصم مجموعته.
        if ($group !== null && (float) $group->discount_percentage > 0) {
            return $this->formatResponse(
                $channelPrice * (1 - (float) $group->discount_percentage / 100),
                'customer_group_discount',
                $channelListId,
                $variant,
                $channel,
            );
        }

        return $this->formatResponse($channelPrice, $channelSource, $channelListId, $variant, $channel);
    }

    /** قناة التسعير: الجملة/الموزع للحسابات المعتمدة فقط، وغير ذلك قطاعي. */
    private function channelFor(?Customer $customer): PriceListType
    {
        if ($customer !== null
            && in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
            && $customer->status === CustomerStatus::Approved) {
            return PriceListType::from($customer->type->value);
        }

        return PriceListType::Retail;
    }

    private function basePrice(ProductVariant $variant, PriceListType $channel): float
    {
        return (float) $variant->base_price;
    }

    /** كل عناصر التسعير السارية لهذا المتغير والكمية والفرع والوقت. */
    private function eligibleItems(?ProductVariant $variant, float $qty, ?int $branchId, Carbon $now): Builder
    {
        return PriceListItem::query()
            ->select('price_list_items.*')
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->where('price_lists.is_active', true)
            ->when($variant !== null,
                fn ($q) => $q->where('price_list_items.product_variant_id', $variant->id),
                fn ($q) => $q->whereColumn('price_list_items.product_variant_id', 'product_variants.id'))
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

    /** ترتيب داخل المستوى الواحد: فرع مخصص أولًا، أعلى شريحة، أعلى أولوية، أقل سعر. */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('price_lists.branch_id IS NULL ASC')
            ->orderByDesc('price_list_items.min_qty')
            ->orderByDesc('price_lists.priority')
            ->orderBy('price_list_items.price');
    }

    /**
     * @return array{unit_price: float, base_price: float, source: string, price_list_id: int|null}
     */
    private function formatResponse(float $unitPrice, string $source, ?int $priceListId, ProductVariant $variant, PriceListType $channel): array
    {
        return [
            'unit_price' => round($unitPrice, 2),
            'base_price' => round($this->basePrice($variant, $channel), 2),
            'source' => $source,
            'price_list_id' => $priceListId,
        ];
    }
}
