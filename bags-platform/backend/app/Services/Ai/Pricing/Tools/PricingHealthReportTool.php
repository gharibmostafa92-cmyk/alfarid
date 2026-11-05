<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\PriceListType;
use App\Enums\ProductStatus;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class PricingHealthReportTool implements AgentTool
{
    public function __construct(
        private PricingService $pricing,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'pricing_health_report';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['price_lists.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'افحص صحة التسعير: قوائم ميتة، بنود مُظلَّلة، تعارضات غامضة، قوائم منتهية أو فاضية، أسعار تحت التكلفة، جملة ≥ قطاعي، أصناف منشورة بلا سعر، لقطة كتالوج قديمة، وانحراف SQL عن PHP. ابدأ بها لأي سؤال "إيه أخبار الأسعار".',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $branchKey = $scope['branchId'] ?? 'all';

        $report = Cache::remember('ai:pricing-health:'.$actor->id.':'.$branchKey, 120, function () use ($actor, $scope): array {
            return $this->buildReport($actor, $scope['branchId']);
        });

        $items = array_map(fn ($check) => [
            'key' => $check['key'],
            'label' => $check['label'],
            'severity' => $check['severity'],
            'detail' => $check['detail'] ?? null,
            'count' => $check['count'] ?? 0,
        ], $report['checks']);

        return $this->presenter->present($actor, $report, $this->presenter->checklist($items));
    }

    /** @return array{checks: list<array<string,mixed>>, score: int} */
    private function buildReport(User $actor, ?int $branchId): array
    {
        $now = Carbon::now();
        $canViewCost = CostAccess::canView($actor);
        $checks = [];

        $lists = PriceList::query()
            ->with(['customer:id,status', 'customerGroup:id,is_active'])
            ->withCount('items')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // 1. Dead lists.
        $dead = $lists->filter(fn (PriceList $l) => $this->isDeadList($l))->values();
        $checks[] = [
            'key' => 'dead_lists',
            'label' => 'قوائم ميتة (عمرها ما هتتطبق)',
            'severity' => $dead->isNotEmpty() ? 'warning' : 'ok',
            'count' => $dead->count(),
            'detail' => $dead->take(10)->map(fn (PriceList $l) => '#'.$l->id.' '.$l->name)->implode('، ') ?: null,
            'list_ids' => $dead->take(10)->pluck('id')->all(),
        ];

        // 2. Shadowed items + 3. ambiguous conflicts need list periods.
        $listById = $lists->keyBy('id');
        $allItems = PriceListItem::query()
            ->with('variant:id,retail_price,wholesale_price,cost_price,product_id')
            ->whereIn('price_list_id', $lists->pluck('id'))
            ->limit(2000)
            ->get();
        $shadowed = $this->findShadowed($allItems, $listById);
        $checks[] = [
            'key' => 'shadowed_items',
            'label' => 'بنود مُظلَّلة (عمرها ما هتكسب)',
            'severity' => $shadowed !== [] ? 'warning' : 'ok',
            'count' => count($shadowed),
            'detail' => $shadowed !== [] ? 'مثال: بند #'.implode('، بند #', array_slice($shadowed, 0, 5)) : null,
            'item_ids' => array_slice($shadowed, 0, 20),
        ];

        $ambiguous = $this->findAmbiguous($lists);
        $checks[] = [
            'key' => 'ambiguous_conflicts',
            'label' => 'تعارض غامض (نفس الأولوية وفترات متداخلة — الكسب "بالأرخص" صدفة)',
            'severity' => $ambiguous !== [] ? 'warning' : 'ok',
            'count' => count($ambiguous),
            'detail' => $ambiguous !== [] ? implode('؛ ', array_slice($ambiguous, 0, 3)) : null,
        ];

        // 4. Expired / empty / scheduled.
        $expired = $lists->filter(fn (PriceList $l) => $l->is_active && $l->ends_at !== null && $l->ends_at->lt($now))->values();
        $checks[] = ['key' => 'expired_active', 'label' => 'قوائم منتهية ولسه نشطة', 'severity' => $expired->isNotEmpty() ? 'warning' : 'ok', 'count' => $expired->count(), 'list_ids' => $expired->take(10)->pluck('id')->all()];
        $empty = $lists->filter(fn (PriceList $l) => $l->is_active && $l->items_count === 0)->values();
        $checks[] = ['key' => 'empty_active', 'label' => 'قوائم مفعّلة من غير بنود', 'severity' => $empty->isNotEmpty() ? 'warning' : 'ok', 'count' => $empty->count(), 'list_ids' => $empty->take(10)->pluck('id')->all()];
        $scheduled = $lists->filter(fn (PriceList $l) => $l->starts_at !== null && $l->starts_at->gt($now) && $l->starts_at->lte($now->copy()->addDays(7)))->values();
        $checks[] = ['key' => 'scheduled', 'label' => 'قوائم مجدولة هتبدأ خلال 7 أيام', 'severity' => 'info', 'count' => $scheduled->count(), 'list_ids' => $scheduled->take(10)->pluck('id')->all()];

        // 5. Below cost.
        $belowCost = $this->findBelowCost($allItems, $canViewCost);
        $checks[] = [
            'key' => 'below_cost',
            'label' => 'بنود تحت التكلفة',
            'severity' => $belowCost['count'] > 0 ? 'critical' : 'ok',
            'count' => $belowCost['count'],
            'detail' => $belowCost['detail'],
            'item_ids' => $belowCost['item_ids'],
        ];

        // 6. Wholesale >= retail.
        $priceInversions = $this->findPriceInversions($allItems);
        $checks[] = [
            'key' => 'wholesale_gte_retail',
            'label' => 'جملة ≥ قطاعي لنفس المتغير',
            'severity' => $priceInversions !== [] ? 'warning' : 'ok',
            'count' => count($priceInversions),
            'detail' => $priceInversions !== [] ? 'متغيرات: '.implode('، ', array_slice($priceInversions, 0, 5)) : null,
        ];

        // 7. Published variants with no price.
        $unpriced = ProductVariant::query()
            ->where('is_active', true)
            ->whereNull('retail_price')
            ->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('price_list_items')->whereColumn('price_list_items.product_variant_id', 'product_variants.id'))
            ->whereHas('product', fn ($q) => $q->where('status', ProductStatus::Published))
            ->limit(21)
            ->pluck('product_variants.id');
        $unpricedCount = $unpriced->count() > 20 ? '20+' : (string) $unpriced->count();
        $checks[] = [
            'key' => 'unpriced_variants',
            'label' => 'متغيرات منشورة من غير أي سعر',
            'severity' => $unpriced->isNotEmpty() ? 'warning' : 'ok',
            'count' => $unpriced->count(),
            'detail' => $unpriced->isNotEmpty() ? "عينة ({$unpricedCount}): ".$unpriced->take(10)->implode('، ') : null,
        ];

        // 8. Stale catalog snapshot.
        $staleCount = Product::query()
            ->whereNotNull('products.catalog_price_refreshed_at')
            ->whereExists(fn ($q) => $q->select(DB::raw('1'))->from('price_list_items')
                ->join('product_variants as pv', 'pv.id', '=', 'price_list_items.product_variant_id')
                ->whereColumn('pv.product_id', 'products.id')
                ->whereColumn('price_list_items.updated_at', '>', 'products.catalog_price_refreshed_at'))
            ->limit(21)
            ->count();
        $checks[] = [
            'key' => 'stale_snapshot',
            'label' => 'لقطة الكتالوج أقدم من آخر تعديل أسعار',
            'severity' => $staleCount > 0 ? 'warning' : 'ok',
            'count' => $staleCount,
        ];

        // 9. SQL vs PHP drift.
        $drift = $this->findDrift($branchId);
        $checks[] = [
            'key' => 'store_price_drift',
            'label' => 'انحراف سعر المتجر (SQL) عن سعر السلة (PHP)',
            'severity' => $drift !== [] ? 'critical' : 'ok',
            'count' => count($drift),
            'detail' => $drift !== [] ? implode('؛ ', array_slice($drift, 0, 3)) : null,
        ];

        $bad = collect($checks)->filter(fn ($c) => in_array($c['severity'], ['critical', 'warning'], true))->count();
        $score = max(0, 100 - $bad * 10 - collect($checks)->filter(fn ($c) => $c['severity'] === 'critical')->count() * 10);

        return ['checks' => $checks, 'score' => $score, 'branch_id' => $branchId, 'scanned_lists' => $lists->count()];
    }

    private function isDeadList(PriceList $list): bool
    {
        $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
        if ($type === PriceListType::CustomerSpecific->value) {
            return $list->customer === null || $list->customer->status !== CustomerStatus::Approved;
        }
        if ($type === PriceListType::CustomerGroup->value) {
            return $list->customerGroup === null || ! $list->customerGroup->is_active;
        }

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceListItem>  $items
     * @param  \Illuminate\Support\Collection<int, PriceList>  $listById
     * @return list<int>
     */
    private function findShadowed($items, $listById): array
    {
        $groups = [];
        foreach ($items as $item) {
            $list = $listById->get($item->price_list_id);
            if (! $list || ! $list->is_active) {
                continue;
            }
            $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
            $owner = $type.'|'.($list->customer_id ?? '').'|'.($list->customer_group_id ?? '');
            $groups[$item->product_variant_id.'|'.$owner.'|'.number_format((float) $item->min_qty, 4, '.', '')][] = [$item, $list];
        }

        $shadowed = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, fn ($a, $b) => (($a[1]->branch_id === null) <=> ($b[1]->branch_id === null))
                ?: ((int) $b[1]->priority <=> (int) $a[1]->priority)
                ?: ((float) $a[0]->price <=> (float) $b[0]->price)
                ?: ((int) $a[0]->id <=> (int) $b[0]->id));
            [$winnerItem, $winnerList] = $group[0];
            foreach (array_slice($group, 1) as [$item, $list]) {
                if ($winnerList->branch_id !== null && $winnerList->branch_id !== $list->branch_id) {
                    continue;
                }
                if (! $this->periodCovers($winnerList->starts_at, $winnerList->ends_at, $list->starts_at, $list->ends_at)) {
                    continue;
                }
                $shadowed[] = (int) $item->id;
            }
        }

        return array_values(array_unique($shadowed));
    }

    private function periodCovers($winnerStart, $winnerEnd, $loserStart, $loserEnd): bool
    {
        $startOk = $winnerStart === null || ($loserStart !== null && !$winnerStart->gt($loserStart));
        $endOk = $winnerEnd === null || ($loserEnd !== null && !$winnerEnd->lt($loserEnd));

        return $startOk && $endOk;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceList>  $lists
     * @return list<string>
     */
    private function findAmbiguous($lists): array
    {
        $groups = [];
        foreach ($lists as $list) {
            if (! $list->is_active) {
                continue;
            }
            $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
            $groups[$type.'|'.($list->customer_id ?? '').'|'.($list->customer_group_id ?? '').'|'.($list->branch_id ?? '').'|'.$list->priority][] = $list;
        }

        $conflicts = [];
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $ids = array_values($group);
            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    if ($this->periodsOverlap($ids[$i]->starts_at, $ids[$i]->ends_at, $ids[$j]->starts_at, $ids[$j]->ends_at)) {
                        $conflicts[] = "قائمتا #{$ids[$i]->id} و#{$ids[$j]->id} (أولوية {$ids[$i]->priority}) فترتاهما متداخلة";
                    }
                }
            }
        }

        return array_slice(array_values(array_unique($conflicts)), 0, 10);
    }

    private function periodsOverlap($startA, $endA, $startB, $endB): bool
    {
        $startOk = $startA === null || $endB === null || !$startA->gt($endB);
        $endOk = $endA === null || $startB === null || !$endA->lt($startB);

        return $startOk && $endOk;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceListItem>  $items
     * @return array{count: int, detail: string|null, item_ids: list<int>}
     */
    private function findBelowCost($items, bool $canViewCost): array
    {
        $maxDiscount = (float) (\App\Models\CustomerGroup::query()->where('is_active', true)->max('discount_percentage') ?? 0);
        $factor = max(0.0, 1.0 - $maxDiscount / 100.0);

        $flagged = [];
        foreach ($items as $item) {
            $cost = $item->variant?->cost_price !== null ? (float) $item->variant->cost_price : null;
            if ($cost === null || $cost <= 0) {
                continue;
            }
            if ((float) $item->price < $cost || (float) $item->price * $factor < $cost) {
                $flagged[] = (int) $item->id;
            }
        }
        $flagged = array_values(array_unique($flagged));

        if ($flagged === []) {
            return ['count' => 0, 'detail' => null, 'item_ids' => []];
        }
        if (! $canViewCost) {
            return ['count' => count($flagged), 'detail' => count($flagged).' بنود مستبعدة لأسباب تسعير محمية', 'item_ids' => []];
        }

        return ['count' => count($flagged), 'detail' => 'بنود: '.implode('، ', array_slice($flagged, 0, 10)), 'item_ids' => array_slice($flagged, 0, 20)];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceListItem>  $items
     * @return list<string>
     */
    private function findPriceInversions($items): array
    {
        $flagged = [];
        foreach ($items as $item) {
            $retail = $item->variant?->retail_price !== null ? (float) $item->variant->retail_price : null;
            if ($retail === null) {
                continue;
            }
            if ((float) $item->price >= $retail) {
                $flagged[] = (string) $item->product_variant_id;
            }
        }
        $baseInversions = ProductVariant::query()
            ->whereNotNull('wholesale_price')
            ->whereNotNull('retail_price')
            ->whereColumn('wholesale_price', '>=', 'retail_price')
            ->limit(20)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return array_values(array_unique([...$flagged, ...$baseInversions]));
    }

    /** @return list<string> */
    private function findDrift(?int $branchId): array
    {
        $variantIds = ProductVariant::query()->where('is_active', true)->orderBy('id')->limit(30)->pluck('id')->all();
        if ($variantIds === []) {
            return [];
        }

        $legs = ['guest' => null];
        $wholesale = \App\Models\Customer::query()->where('type', CustomerType::Wholesale)->where('status', CustomerStatus::Approved)->orderBy('id')->first();
        $distributor = \App\Models\Customer::query()->where('type', CustomerType::Distributor)->where('status', CustomerStatus::Approved)->orderBy('id')->first();
        if ($wholesale) {
            $legs['wholesale'] = $wholesale;
        }
        if ($distributor) {
            $legs['distributor'] = $distributor;
        }

        $mismatches = [];
        foreach ($legs as $legName => $customer) {
            $storePrices = $this->pricing->withStorePrice(ProductVariant::query()->whereIn('product_variants.id', $variantIds), $customer)
                ->pluck('store_price', 'id');
            foreach ($variantIds as $variantId) {
                try {
                    $resolved = $this->pricing->resolve(ProductVariant::query()->findOrFail($variantId), $customer, 1, $branchId)['unit_price'];
                } catch (\Throwable) {
                    continue;
                }
                $store = isset($storePrices[$variantId]) ? round((float) $storePrices[$variantId], 2) : null;
                if ($store !== null && abs($store - round((float) $resolved, 2)) > 0.009) {
                    $mismatches[] = "متغير #{$variantId} ({$legName}): المتجر {$store} ≠ السلة {$resolved}";
                    if (count($mismatches) >= 10) {
                        return $mismatches;
                    }
                }
            }
        }

        return $mismatches;
    }
}
