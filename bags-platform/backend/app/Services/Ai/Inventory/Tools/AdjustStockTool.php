<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\DiscreteQuantity;
use App\Services\InventoryService;
use App\Services\SettingService;

final class AdjustStockTool implements LocksTargets, UndoableTool, WriteTool
{
    public const REASONS = ['تالف', 'فقد', 'خطأ إدخال', 'عينة', 'هدية'];

    /** @var array<string, list<array{0:string}>> Inverse movement mapping for compensating ledger adjustments. */
    private const INVERSE = [
        'increase' => [['decrease']],
        'decrease' => [['increase']],
        'mark_damaged' => [['restore_damaged']],
        'restore_damaged' => [['mark_damaged']],
        'write_off_damaged' => [['increase'], ['mark_damaged']],
    ];

    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryService $inventory,
        private ProposalBuilder $proposals,
        private SettingService $settings,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'adjust_stock';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['inventory.adjust'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تسوية مخزون (زيادة/خصم/تالف) على متغير في فرع. السبب إجباري من قائمة ثابتة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['quantity', 'adjustment_type'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'location_id' => ['type' => 'INTEGER'],
                    'adjustment_type' => ['type' => 'STRING', 'enum' => ['increase', 'decrease', 'mark_damaged', 'write_off_damaged', 'restore_damaged']],
                    'quantity' => ['type' => 'NUMBER'],
                    'reason' => ['type' => 'STRING', 'enum' => self::REASONS],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
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
        $type = (string) ($args['adjustment_type'] ?? '');
        if (! in_array($type, ['increase', 'decrease', 'mark_damaged', 'write_off_damaged', 'restore_damaged'], true)) {
            return ToolOutcome::denied('نوع التسوية لازم يكون زيادة أو خصم أو تالف.', auditable: false);
        }
        $reason = trim((string) ($args['reason'] ?? ''));
        if (! in_array($reason, self::REASONS, true)) {
            return ToolOutcome::picker([
                'type' => 'reason_picker',
                'field' => 'reason',
                'purpose' => 'سبب التسوية إجباري — اختار واحد من القائمة',
                'options' => array_map(fn ($r) => ['id' => $r, 'label' => $r], self::REASONS),
            ]);
        }
        $qty = $args['quantity'] ?? null;
        try {
            DiscreteQuantity::assert($qty);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }
        $qty = (float) $qty;

        $variant = $resolved['variant'];
        $branchId = $scope['branch']->id;
        $locationId = isset($args['location_id']) ? (int) $args['location_id'] : null;
        $level = $this->inventory->levelFor($variant->id, $branchId, $locationId);
        $before = [
            'on_hand' => (float) ($level?->on_hand ?? 0),
            'reserved' => (float) ($level?->reserved ?? 0),
            'damaged' => (float) ($level?->damaged ?? 0),
            'available' => $level?->available ?? 0.0,
        ];

        // Deductions cannot exceed available stock minus damaged and reserved quantities.
        if (in_array($type, ['write_off_damaged', 'restore_damaged'], true) && $qty > $before['damaged']) {
            return ToolOutcome::denied("التالف {$before['damaged']} بس؛ كمية التسوية أكبر من التالف.", auditable: false);
        }
        if (in_array($type, ['decrease', 'mark_damaged'], true) && $qty > $before['available']) {
            return ToolOutcome::denied(
                "المتاح {$before['available']} بس، لأن فيه {$before['reserved']} محجوزين و{$before['damaged']} تالف. تحب أفك الحجز؟",
                auditable: false,
            );
        }

        $after = $before;
        match ($type) {
            'increase' => $after['on_hand'] += $qty,
            'decrease' => $after['on_hand'] -= $qty,
            'mark_damaged' => $after['damaged'] += $qty,
            'write_off_damaged' => [$after['damaged'] -= $qty, $after['on_hand'] -= $qty],
            'restore_damaged' => $after['damaged'] -= $qty,
            default => null,
        };
        $after['available'] = round($after['on_hand'] - $after['reserved'] - $after['damaged'], 4);

        $cost = $variant->cost_price !== null ? (float) $variant->cost_price : 0.0;
        $value = round($qty * $cost, 2);
        $threshold = (float) $this->settings->get('ai_inventory_high_risk_value', 50000);
        $risk = $value > $threshold ? 'high' : $this->risk();
        $warnings = [];
        if ($variant->cost_price === null) {
            $warnings[] = 'مفيش تكلفة مسجلة للصنف — القيمة محسوبة بصفر.';
        }
        if ($risk === 'high') {
            $warnings[] = 'قيمة التسوية فوق الحد المسموح — التأكيد بالكتابة مطلوب.';
        }

        $key = $this->versionKey($variant->id, $branchId, $locationId);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'variant_id' => $variant->id,
                'branch_id' => $branchId,
                'location_id' => $locationId,
                'adjustment_type' => $type,
                'quantity' => $qty,
                'reason' => $reason,
            ],
            risk: $risk,
            title: "تسوية {$reason} — {$variant->sku}",
            before: $before,
            after: $after,
            warnings: $warnings,
            expectedVersions: [$key => $this->lastMovementId($variant->id, $branchId, $locationId)],
            typedConfirmation: $risk === 'high' ? 'تنفيذ' : null,
            extraPreview: ['block_type' => 'stock_diff', 'sku' => $variant->sku],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $this->inventory->lockLevels([[
            'product_variant_id' => (int) $action->args['variant_id'],
            'branch_id' => (int) $action->args['branch_id'],
            'location_id' => $action->args['location_id'] ?? null,
        ]]);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $key = $this->versionKey((int) $a['variant_id'], (int) $a['branch_id'], $a['location_id'] ?? null);
        $expected = $action->expected_versions[$key] ?? null;
        $current = $this->lastMovementId((int) $a['variant_id'], (int) $a['branch_id'], $a['location_id'] ?? null);
        if ($expected !== $current) {
            throw new BusinessException('السجل اتعدل بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
        }

        $movements = $this->inventory->applyAdjustments([
            'branch_id' => (int) $a['branch_id'],
            'location_id' => $a['location_id'] ?? null,
            'items' => [[
                'product_variant_id' => (int) $a['variant_id'],
                'adjustment_type' => $a['adjustment_type'],
                'quantity' => $a['quantity'],
                'reason' => $a['reason'],
            ]],
        ], $actor->id, 'ai:'.$action->id);

        $variant = ProductVariant::find((int) $a['variant_id']);
        $level = $this->inventory->levelFor((int) $a['variant_id'], (int) $a['branch_id'], $a['location_id'] ?? null);

        return ToolOutcome::result(true, "تمت تسوية {$a['reason']} للصنف {$variant?->sku}.", [
            'movement_ids' => array_map(fn ($m) => $m->id, $movements),
            'available' => $level?->available,
            'deep_link' => '#/inventory',
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return isset($action->args['adjustment_type'], self::INVERSE[$action->args['adjustment_type']]);
    }

    public function targetState(AiPendingAction $action): string
    {
        $a = $action->args;

        return hash('sha256', json_encode([
            $this->lastMovementId((int) $a['variant_id'], (int) $a['branch_id'], $a['location_id'] ?? null),
        ], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $items = array_map(fn ($pair) => [
            'product_variant_id' => (int) $a['variant_id'],
            'adjustment_type' => $pair[0],
            'quantity' => $a['quantity'],
            'reason' => 'تراجع تسوية AI',
        ], self::INVERSE[$a['adjustment_type']]);

        $this->inventory->applyAdjustments([
            'branch_id' => (int) $a['branch_id'],
            'location_id' => $a['location_id'] ?? null,
            'items' => $items,
        ], $actor->id, 'ai:undo:'.$action->id);

        return ToolOutcome::result(true, 'تم التراجع عن التسوية بحركات عكسية.', [
            'deep_link' => '#/inventory',
        ]);
    }

    private function versionKey(int $variantId, int $branchId, ?int $locationId): string
    {
        return 'movement:'.$variantId.':'.$branchId.':'.($locationId ?? 0);
    }

    private function lastMovementId(int $variantId, int $branchId, ?int $locationId): ?int
    {
        return StockMovement::query()
            ->where('product_variant_id', $variantId)
            ->where('branch_id', $branchId)
            ->where('location_id', $locationId)
            ->orderByDesc('id')->value('id');
    }
}
