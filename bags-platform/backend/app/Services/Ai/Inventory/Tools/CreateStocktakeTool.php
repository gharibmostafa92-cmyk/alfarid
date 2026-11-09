<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\StocktakeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Shared\DiscreteQuantity;

final class CreateStocktakeTool extends StocktakeToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'create_stocktake';
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
        return ['stocktakes.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'فتح جلسة جرد جديدة في فرع (وموقع اختياري) مع بنود اختيارية بعدّ مبدئي.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['branch_id'],
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'location_id' => ['type' => 'INTEGER'],
                    'notes' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => [
                        'type' => 'OBJECT',
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
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        if ($scope['branch'] === null) {
            return ToolOutcome::denied('حدد الفرع بـ branch_id.', auditable: false);
        }
        $items = [];
        if (array_key_exists('items', $args)) {
            $parsed = $this->parseCountItems($actor, $args['items']);
            if ($parsed instanceof ToolOutcome) {
                return $parsed;
            }
            $items = $parsed;
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'branch_id' => $scope['branch']->id,
                'location_id' => $args['location_id'] ?? null,
                'notes' => $args['notes'] ?? null,
                'items' => $items,
            ],
            risk: $this->risk(),
            title: "جرد جديد — {$scope['branch']->name}",
            before: ['sessions' => []],
            after: ['branch' => $scope['branch']->name, 'items_count' => count($items)],
        );
    }

    /**
     * @return list<array{product_variant_id:int,counted_qty:?float}>|ToolOutcome
     */
    private function parseCountItems(User $actor, mixed $rawItems): array|ToolOutcome
    {
        if (! is_array($rawItems) || count($rawItems) > 500) {
            return ToolOutcome::denied('بنود الجرد غير سليمة (بحد أقصى 500).', auditable: false);
        }
        $out = [];
        foreach ($rawItems as $i => $raw) {
            if (! is_array($raw)) {
                return ToolOutcome::denied('بند رقم '.($i + 1).' غير سليم.', auditable: false);
            }
            $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, [
                'variant_id' => $raw['product_variant_id'] ?? $raw['variant_id'] ?? null,
                'sku' => $raw['sku'] ?? null,
                'query' => $raw['query'] ?? null,
            ]);
            if ($resolved instanceof ToolOutcome) {
                return $resolved;
            }
            $counted = $raw['counted_qty'] ?? null;
            if ($counted !== null) {
                try {
                    DiscreteQuantity::assert($counted, allowZero: true);
                } catch (BusinessException $e) {
                    return ToolOutcome::denied($e->getMessage(), auditable: false);
                }
            }
            $out[] = ['product_variant_id' => $resolved['variant']->id, 'counted_qty' => $counted !== null ? (float) $counted : null];
        }

        return $out;
    }

    public function lockTargets(AiPendingAction $action): void
    {
        // No existing records to acquire pessimistic locks on prior to session creation.
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $stocktake = $this->stocktakes->create([
            'branch_id' => (int) $a['branch_id'],
            'location_id' => $a['location_id'] ?? null,
            'notes' => $a['notes'] ?? null,
            'items' => array_map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'],
                'counted_qty' => $i['counted_qty'] ?? null,
            ], $a['items'] ?? []),
        ], $actor->id, 'ai-stocktake:'.$action->id);

        return ToolOutcome::result(true, "اتفتحت جلسة الجرد {$stocktake->stocktake_number}.", [
            'stocktake_id' => $stocktake->id,
            'stocktake_number' => $stocktake->stocktake_number,
            'deep_link' => '#/stocktakes/'.$stocktake->id,
        ]);
    }
}
