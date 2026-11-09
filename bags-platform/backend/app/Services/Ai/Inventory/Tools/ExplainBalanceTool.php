<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\InventoryService;

final class ExplainBalanceTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryService $inventory,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'explain_balance';
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
            'description' => 'يشرح كيف وصل رصيد متغير لرقمه الحالي: آخر الحركات مجمعة حسب النوع والمرجع مع روابط المستندات.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['branch_id'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'location_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        if ($scope['branch'] === null) {
            return ToolOutcome::denied('حدد الفرع بـ branch_id.', auditable: false);
        }
        $variant = $resolved['variant'];
        $branchId = $scope['branch']->id;
        $locationId = isset($args['location_id']) ? (int) $args['location_id'] : null;
        if (! array_key_exists('location_id', $args)) {
            $locations = StockLevel::where('product_variant_id', $variant->id)->where('branch_id', $branchId)
                ->distinct()->limit(2)->pluck('location_id');
            if ($locations->count() > 1) {
                return ToolOutcome::denied('الصنف موجود في أكتر من موقع؛ حدد الموقع علشان أشرح رصيده بدقة.', auditable: false);
            }
            $locationId = $locations->first();
        }

        $level = $this->inventory->levelFor($variant->id, $branchId, $locationId);
        $movements = StockMovement::query()
            ->where('product_variant_id', $variant->id)
            ->where('branch_id', $branchId)
            ->where('location_id', $locationId)
            ->where('affected_bucket', 'on_hand')
            ->orderByDesc('id')->limit(20)->get();

        $groups = [];
        foreach ($movements as $m) {
            $key = $m->type->value.'|'.($m->reference_number ?? '');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'type' => $m->type->value,
                    'reference_number' => $m->reference_number,
                    'reference_link' => $this->referenceLink($m->reference_type, $m->reference_id),
                    'count' => 0,
                    'quantity_total' => 0.0,
                ];
            }
            $groups[$key]['count']++;
            $groups[$key]['quantity_total'] += (float) $m->quantity;
        }

        $start = $movements->isNotEmpty() ? (float) $movements->last()->balance_before : 0.0;
        $timeline = $movements->reverse()->values()->map(fn (StockMovement $m) => [
            'id' => $m->id,
            'created_at' => $m->created_at?->toIso8601String(),
            'type' => $m->type->value,
            'quantity' => (float) $m->quantity,
            'balance_after' => (float) $m->balance_after,
            'reference_number' => $m->reference_number,
            'reference_link' => $this->referenceLink($m->reference_type, $m->reference_id),
            'notes' => $m->notes !== null ? $this->presenter->untrusted($m->notes, 200) : null,
        ])->values()->all();

        return $this->presenter->present($actor, [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'branch_id' => $branchId,
            'current' => $level ? [
                'on_hand' => (float) $level->on_hand,
                'reserved' => (float) $level->reserved,
                'damaged' => (float) $level->damaged,
                'in_transit' => (float) $level->in_transit,
                'available' => $level->available,
            ] : null,
            'window_start_balance' => $start,
            'window_movements' => $movements->count(),
            'groups' => array_values($groups),
        ], ['type' => 'balance_story', 'start_balance' => $start, 'groups' => array_values($groups), 'timeline' => $timeline]);
    }

    private function referenceLink(?string $type, mixed $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }

        return match ($type) {
            'stock_transfer' => '#/transfers/'.$id,
            'stocktake' => '#/stocktakes/'.$id,
            'order' => '#/orders/'.$id,
            'return_request' => '#/returns/'.$id,
            'purchase_order' => '#/purchases/'.$id,
            default => null,
        };
    }
}
