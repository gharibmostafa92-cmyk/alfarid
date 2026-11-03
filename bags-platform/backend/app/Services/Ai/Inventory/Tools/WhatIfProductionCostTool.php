<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\DiscreteQuantity;
use Illuminate\Support\Facades\DB;

final class WhatIfProductionCostTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'what_if_production_cost';
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
        return ['inventory.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يحسب متوسط التكلفة المرجح لو استلمت كمية بسعر معين — من غير ما يكتب حاجة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['quantity', 'unit_cost'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'quantity' => ['type' => 'INTEGER'],
                    'unit_cost' => ['type' => 'NUMBER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        // سؤال مباشر عن التكلفة + رقم جاي من نتيجة أداة = مسموح يظهر في الرد.
        if (! CostAccess::canView($actor)) {
            return ToolOutcome::denied('السؤال عن التكلفة محتاج صلاحية رؤية التكلفة.', auditable: false);
        }
        $args = InventoryArgs::normalize($args);
        $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        try {
            DiscreteQuantity::assert($args['quantity'] ?? null);
        } catch (\App\Exceptions\BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }
        $unit = $args['unit_cost'] ?? null;
        if (! is_numeric($unit) || (float) $unit < 0) {
            return ToolOutcome::denied('تكلفة الوحدة غير سليمة.', auditable: false);
        }
        $variant = ProductVariant::find($resolved['variant']->id);
        $qty = (int) $args['quantity'];

        $onHand = (string) StockLevel::where('product_variant_id', $variant->id)->sum(DB::raw('on_hand + in_transit'));
        $onHand = bccomp($onHand, '0', 4) < 0 ? '0' : $onHand;
        $costStr = (string) $variant->cost_price;
        $total = bcadd(bcmul($onHand, $costStr, 8), bcmul((string) $qty, (string) $unit, 8), 8);
        $denominator = bcadd($onHand, (string) $qty, 4);
        $newAvg = bccomp($denominator, '0', 4) === 0 ? $costStr : bcadd(bcdiv($total, $denominator, 5), '0.00005', 4);
        $pct = $variant->cost_price > 0 ? round(((float) $newAvg - (float) $variant->cost_price) / (float) $variant->cost_price * 100, 1) : null;

        return ToolOutcome::data([
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'quantity' => $qty,
            'unit_cost' => (float) $unit,
            'cost_before' => (float) $variant->cost_price,
            'cost_after' => (float) $newAvg,
            'change_pct' => $pct,
            'on_hand_before' => (float) $onHand,
        ], ['type' => 'cost_impact', 'rows' => [[
            'sku' => $variant->sku,
            'quantity' => $qty,
            'unit_cost' => (float) $unit,
            'cost_before' => (float) $variant->cost_price,
            'cost_after' => (float) $newAvg,
            'change_pct' => $pct,
        ]]]);
    }
}
