<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\DiscreteQuantity;

final class RecordCountsTool extends StocktakeToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'record_counts';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['stocktakes.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تسجيل العد الفعلي لبنود جلسة جرد مفتوحة (الصفر عدّ سليم).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['items'],
                'properties' => (object) [
                    'stocktake_id' => ['type' => 'INTEGER'],
                    'stocktake_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => [
                        'type' => 'OBJECT',
                        'required' => ['counted_qty'],
                        'properties' => (object) [
                            'variant_id' => ['type' => 'INTEGER'],
                            'product_variant_id' => ['type' => 'INTEGER'],
                            'sku' => ['type' => 'STRING'],
                            'query' => ['type' => 'STRING'],
                            'counted_qty' => ['type' => 'INTEGER'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $resolved = $this->resolveStocktake($actor, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $stocktake = $resolved['stocktake'];
        if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return ToolOutcome::denied('الجرد مقفول (الحالة: '.$stocktake->status->value.').', auditable: false);
        }
        $rawItems = $args['items'] ?? null;
        if (! is_array($rawItems) || $rawItems === [] || count($rawItems) > 500) {
            return ToolOutcome::denied('حدد بندًا واحدًا على الأقل (بحد أقصى 500).', auditable: false);
        }
        $items = [];
        foreach ($rawItems as $i => $raw) {
            if (! is_array($raw)) {
                return ToolOutcome::denied('بند رقم '.($i + 1).' غير سليم.', auditable: false);
            }
            $match = InventoryArgs::resolveVariant($actor, $this->resolver, [
                'variant_id' => $raw['product_variant_id'] ?? $raw['variant_id'] ?? null,
                'sku' => $raw['sku'] ?? null,
                'query' => $raw['query'] ?? null,
            ]);
            if ($match instanceof ToolOutcome) {
                return $match;
            }
            try {
                DiscreteQuantity::assert($raw['counted_qty'] ?? null, allowZero: true);
            } catch (BusinessException $e) {
                return ToolOutcome::denied($e->getMessage(), auditable: false);
            }
            $items[] = ['product_variant_id' => $match['variant']->id, 'counted_qty' => (float) $raw['counted_qty'], 'sku' => $match['variant']->sku];
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['stocktake_id' => $stocktake->id, 'items' => array_map(fn ($i) => ['product_variant_id' => $i['product_variant_id'], 'counted_qty' => $i['counted_qty']], $items)],
            risk: $this->risk(),
            title: "تسجيل عد — {$stocktake->stocktake_number}",
            before: ['counted' => $stocktake->items->whereNotNull('counted_qty')->count(), 'total' => $stocktake->items->count()],
            after: ['counts' => array_map(fn ($i) => $i['sku'].': '.$i['counted_qty'], $items)],
            extraPreview: ['block_type' => 'count_sheet', 'rows' => $items],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $stocktake = Stocktake::findOrFail((int) $a['stocktake_id']);
        $updated = $this->stocktakes->upsertSomeItems($stocktake, array_map(fn ($i) => [
            'product_variant_id' => (int) $i['product_variant_id'],
            'counted_qty' => $i['counted_qty'],
        ], $a['items']), $actor->id);
        $counted = $updated->items->whereNotNull('counted_qty')->count();

        return ToolOutcome::result(true, "اتسجل العد في {$updated->stocktake_number} ({$counted} من {$updated->items->count()}).", [
            'stocktake_id' => $updated->id,
            'stocktake_number' => $updated->stocktake_number,
            'counted' => $counted,
            'total' => $updated->items->count(),
            'deep_link' => '#/stocktakes/'.$updated->id,
        ]);
    }
}
