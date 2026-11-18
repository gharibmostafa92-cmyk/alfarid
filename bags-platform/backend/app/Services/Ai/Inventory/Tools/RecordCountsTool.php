<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Shared\DiscreteQuantity;
use Illuminate\Support\Facades\DB;

final class RecordCountsTool extends StocktakeToolBase implements AgentTool, LocksTargets, WriteTool
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

        $existingCounts = $stocktake->items->keyBy('product_variant_id');
        $expectedVersions = ['stocktake:'.$stocktake->id => $stocktake->status->value];
        foreach ($items as $item) {
            $existing = $existingCounts->get($item['product_variant_id']);
            $expectedVersions['item:'.$item['product_variant_id']] = $existing && $existing->counted_qty !== null
                ? (string) (float) $existing->counted_qty
                : 'null';
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['stocktake_id' => $stocktake->id, 'items' => array_map(fn ($i) => ['product_variant_id' => $i['product_variant_id'], 'counted_qty' => $i['counted_qty']], $items)],
            risk: $this->risk(),
            title: "تسجيل عد — {$stocktake->stocktake_number}",
            before: ['counted' => $stocktake->items->whereNotNull('counted_qty')->count(), 'total' => $stocktake->items->count()],
            after: ['counts' => array_map(fn ($i) => $i['sku'].': '.$i['counted_qty'], $items)],
            expectedVersions: $expectedVersions,
            extraPreview: ['block_type' => 'count_sheet', 'rows' => $items, 'stocktake_id' => $stocktake->id, 'stocktake_number' => $stocktake->stocktake_number],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Stocktake::query()->whereKey((int) ($action->args['stocktake_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $updated = DB::transaction(function () use ($a, $action, $actor): Stocktake {
            $stocktake = Stocktake::query()->with('items')->lockForUpdate()->findOrFail((int) $a['stocktake_id']);
            $expectedStatus = $action->expected_versions['stocktake:'.$stocktake->id] ?? null;
            if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)
                || ($expectedStatus !== null && $stocktake->status->value !== $expectedStatus)) {
                throw new BusinessException('حالة الجرد تغيّرت أثناء المراجعة؛ اطلب الاقتراح من جديد.', [], 409);
            }

            $existingCounts = $stocktake->items->keyBy('product_variant_id');
            foreach ($a['items'] as $i) {
                $vid = (int) $i['product_variant_id'];
                $expectedKey = 'item:'.$vid;
                if (array_key_exists($expectedKey, $action->expected_versions ?? [])) {
                    $existing = $existingCounts->get($vid);
                    $currentVal = $existing && $existing->counted_qty !== null ? (string) (float) $existing->counted_qty : 'null';
                    if ($currentVal !== $action->expected_versions[$expectedKey]) {
                        throw new BusinessException('تغيّر عدّ أحد الأصناف من شاشة أخرى أثناء المراجعة؛ اطلب الاقتراح من جديد.', [], 409);
                    }
                }
            }

            return $this->stocktakes->upsertSomeItems($stocktake, array_map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'],
                'counted_qty' => $i['counted_qty'],
            ], $a['items']), $actor->id);
        });
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
