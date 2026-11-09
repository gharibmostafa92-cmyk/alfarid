<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Catalog\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\PriceListItem;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\CostAccess;
use App\Services\Pricing\PriceListService;
use App\Services\Pricing\PricingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class WhatIfPriceChangeTool implements AgentTool
{
    private const MAX_ITEMS = 30;

    public function __construct(
        private PriceListService $lists,
        private PricingService $pricing,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'what_if_price_change';
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
            'description' => 'حاكي تأثير تعديل قائمة أسعار بنسبة مئوية على عينة من البنود والعملاء من غير حفظ الأسعار.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id', 'percent'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'percent' => ['type' => 'NUMBER'],
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $percent = $args['percent'] ?? null;
        if (! is_numeric($percent) || ! is_finite((float) $percent) || $percent <= -100 || $percent > 1000) {
            return ToolOutcome::denied('حدد نسبة أكبر من ‎-100٪ وحتى 1000٪.', auditable: false);
        }
        try {
            $list = $this->lists->find((int) ($args['price_list_id'] ?? 0), $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args + ['branch_id' => $list->branch_id]);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $branchId = $scope['branchId'];
        if ($list->branch_id !== null && (int) $list->branch_id !== $branchId) {
            return ToolOutcome::denied('اختار الفرع المرتبط بقائمة الأسعار.', auditable: false);
        }

        $identities = ['قطاعي' => null];
        foreach ([CustomerType::Wholesale, CustomerType::Distributor] as $type) {
            $identities[$type->value] = new Customer(['type' => $type, 'status' => CustomerStatus::Approved]);
        }
        if ($actor->can('customers.view')) {
            $members = Customer::query()->where('status', CustomerStatus::Approved)
                ->where(fn ($query) => $query->whereKey($list->customer_id ?? 0)->orWhereNotNull('customer_group_id'))
                ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$list->customer_id ?? 0])
                ->orderBy('id')->limit(10)->get();
            foreach ($members as $customer) {
                $identities['عميل #'.$customer->id] = $customer;
            }
        }

        $rows = [];
        $inversions = 0;
        $affected = [];
        $canViewCost = CostAccess::canView($actor);
        DB::beginTransaction();
        try {
            $locked = $list->newQuery()->lockForUpdate()->findOrFail($list->id);
            $this->lists->assertListAccess($actor, $locked);
            $items = $locked->items()->whereHas('variant')->with('variant')->orderBy('id')->limit(self::MAX_ITEMS)->get();
            $before = [];
            foreach ($items as $item) {
                foreach ($identities as $label => $customer) {
                    $before[$item->id][$label] = $this->resolve($item, $customer, $branchId);
                }
                $price = round((float) $item->price * (1 + (float) $percent / 100), 4);
                if ($price <= 0 || $price > 99999999.9999) {
                    return ToolOutcome::denied('التعديل المقترح خارج نطاق الأسعار المسموح.', auditable: false);
                }
                if (in_array($locked->type, [PriceListType::Wholesale, PriceListType::Distributor], true)
                    && $item->variant->retail_price !== null && $price >= (float) $item->variant->retail_price) {
                    $inversions++;
                }
            }

            foreach ($items as $item) {
                DB::table('price_list_items')->where('id', $item->id)->update([
                    'price' => round((float) $item->price * (1 + (float) $percent / 100), 4),
                ]);
            }
            foreach ($items as $item) {
                foreach ($identities as $label => $customer) {
                    $old = $before[$item->id][$label];
                    $new = $this->resolve($item, $customer, $branchId);
                    if ($old === $new) {
                        continue;
                    }
                    if ($customer?->exists) {
                        $affected[$customer->id] = true;
                    }
                    $cost = $item->variant->cost_price;
                    $rows[] = [$item->variant->sku, $item->min_qty, $label, $old, $new,
                        $canViewCost && $new !== null && $new > 0 && $cost !== null ? round(100 * ($new - (float) $cost) / $new, 2) : null];
                }
            }
        } finally {
            DB::rollBack();
        }

        $data = [
            'sampled_items' => $items->count(),
            'sampled_customers_affected' => count($affected),
            'wholesale_at_or_above_retail' => $inversions,
            'changed_quotes' => count($rows),
            'sample' => array_slice($rows, 0, 30),
        ];
        $columns = ['SKU', 'الكمية', 'العميل أو القناة', 'قبل', 'بعد', 'الهامش %'];

        return $this->presenter->present($actor, $data, $this->presenter->table($columns, $data['sample']),
            'النتيجة مبنية على عينة من القائمة والعملاء. لم تُحفظ تغييرات.');
    }

    private function resolve(PriceListItem $item, ?Customer $customer, ?int $branchId): ?float
    {
        try {
            return $this->pricing->resolve($item->variant, $customer, (float) $item->min_qty, $branchId)['unit_price'];
        } catch (BusinessException) {
            return null;
        }
    }
}
