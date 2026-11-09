<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing;

use App\Models\Coupon;
use App\Models\PriceList;
use App\Models\User;
use App\Services\Ai\Pricing\Tools\PricingHealthReportTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\CostAccess;
use Illuminate\Support\Facades\Cache;

final class PricingWelcome
{
    public function __construct(
        private BranchAccess $branches,
        private PricingToolProvider $provider,
        private PricingHealthReportTool $health,
    ) {}

    public function card(User $actor): ?array
    {
        if (! $this->provider->enabled() || ! $actor->can('price_lists.view')) {
            return null;
        }
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, []);
        if (! is_array($scope)) {
            return null;
        }
        $branchId = $scope['branchId'];
        $canSeeCoupons = $this->provider->canReadEntity($actor, 'coupon');
        $canSeeCost = CostAccess::canView($actor);
        $key = implode(':', ['ai', 'pricing-welcome', $actor->id, $branchId ?? 'all', (int) $canSeeCost, (int) $canSeeCoupons]);

        return Cache::remember($key, 60, function () use ($actor, $branchId, $canSeeCoupons, $canSeeCost): array {
            $now = now();
            $expiring = PriceList::query()->where('is_active', true)
                ->whereBetween('ends_at', [$now, $now->copy()->addDays(7)])
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $rows = [['label' => 'قوائم هتنتهي خلال 7 أيام', 'value' => $expiring]];
            $actions = [
                ['label' => 'افحص صحة التسعير', 'prompt' => 'افحص صحة التسعير'],
                ['label' => 'اشرح سعر صنف', 'prompt' => 'اشرح سعر صنف لعميل'],
            ];
            if ($canSeeCoupons) {
                $nearLimit = Coupon::query()->where('is_active', true)
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                    ->where('usage_limit', '>', 0)
                    ->whereRaw('used_count >= usage_limit * 0.9')->count();
                $rows[] = ['label' => 'كوبونات وصلت إلى 90٪ من الحد أو أكتر', 'value' => $nearLimit];
                $actions[] = ['label' => 'الكوبونات اللي قربت تخلص', 'prompt' => 'اعرض الكوبونات اللي قربت تخلص'];
            }
            $report = $this->health->report($actor, $branchId);
            $rows[] = ['label' => 'درجة صحة التسعير', 'value' => $report['score']];
            if ($canSeeCost) {
                $check = collect($report['checks'])->firstWhere('key', 'below_cost');
                $rows[] = ['label' => 'بنود تحت التكلفة في الفحص', 'value' => $check['count'] ?? 0];
            }

            return ['type' => 'welcome_card', 'title' => 'ملخص التسعير', 'rows' => $rows, 'quick_actions' => $actions];
        });
    }
}
