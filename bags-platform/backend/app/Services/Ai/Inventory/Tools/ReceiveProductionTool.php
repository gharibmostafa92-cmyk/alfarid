<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\ProductionReceipt;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\CostAccess;
use App\Services\Shared\DiscreteQuantity;
use App\Services\Inventory\ProductionReceiptService;
use App\Services\Shared\SettingService;
use Illuminate\Support\Facades\DB;

final class ReceiveProductionTool implements LocksTargets, WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private ProductionReceiptService $receipts,
        private ProposalBuilder $proposals,
        private SettingService $settings,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'receive_production';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['inventory.adjust'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'استلام دفعة إنتاج: يضيف الرصيد ويعيد حساب متوسط التكلفة المرجح. بلا تراجع.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['batch_number', 'items'],
                'properties' => (object) [
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                    'location_id' => ['type' => 'INTEGER'],
                    'batch_number' => ['type' => 'STRING'],
                    'notes' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => [
                        'type' => 'OBJECT',
                        'required' => ['quantity', 'unit_cost'],
                        'properties' => (object) [
                            'product_variant_id' => ['type' => 'INTEGER'],
                            'variant_id' => ['type' => 'INTEGER'],
                            'sku' => ['type' => 'STRING'],
                            'quantity' => ['type' => 'INTEGER'],
                            'unit_cost' => ['type' => 'NUMBER'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        // Restrict execution early when actor lacks permission to view cost figures.
        if (! CostAccess::canView($actor)) {
            return ToolOutcome::denied('استلام الإنتاج محتاج صلاحية رؤية التكلفة.', auditable: false);
        }
        $args = InventoryArgs::normalize($args);
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        if ($scope['branch'] === null) {
            return ToolOutcome::denied('حدد الفرع بـ branch_id.', auditable: false);
        }
        $batch = mb_strtoupper(trim((string) ($args['batch_number'] ?? '')));
        if ($batch === '') {
            return ToolOutcome::denied('رقم الدفعة مطلوب.', auditable: false);
        }
        // Validate batch uniqueness before building proposals to avoid aborting upon user confirmation.
        if (ProductionReceipt::whereRaw('LOWER(batch_number) = ?', [mb_strtolower($batch)])->exists()) {
            return ToolOutcome::denied('رقم الدفعة مسجل بالفعل؛ راجع سجل الاستلام.', auditable: false);
        }
        $rawItems = $args['items'] ?? null;
        if (! is_array($rawItems) || $rawItems === [] || count($rawItems) > 50) {
            return ToolOutcome::denied('حدد بندًا واحدًا على الأقل (بحد أقصى 50).', auditable: false);
        }

        // Resolve variant entities and aggregate duplicate lines into consolidated batch items.
        $grouped = [];
        foreach ($rawItems as $i => $raw) {
            if (! is_array($raw)) {
                return ToolOutcome::denied('بند رقم '.($i + 1).' غير سليم.', auditable: false);
            }
            $cand = ['variant_id' => $raw['product_variant_id'] ?? $raw['variant_id'] ?? null, 'sku' => $raw['sku'] ?? null, 'query' => $raw['query'] ?? null];
            $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $cand);
            if ($resolved instanceof ToolOutcome) {
                return $resolved;
            }
            $variant = $resolved['variant'];
            if (! $variant->is_active) {
                return ToolOutcome::denied("المتغير {$variant->sku} معطّل.", auditable: false);
            }
            try {
                DiscreteQuantity::assertWhole($raw['quantity'] ?? null, false, 'كمية استلام الإنتاج لازم تكون عددًا صحيحًا.');
            } catch (BusinessException $e) {
                return ToolOutcome::denied($e->getMessage(), auditable: false);
            }
            $unit = $raw['unit_cost'] ?? null;
            if (! DiscreteQuantity::isValid($unit, allowZero: true)) {
                return ToolOutcome::denied("تكلفة البند {$variant->sku} غير سليمة.", auditable: false);
            }
            $grouped[$variant->id]['variant'] = $variant;
            $grouped[$variant->id]['quantity'] = ($grouped[$variant->id]['quantity'] ?? 0) + (int) $raw['quantity'];
            if ($grouped[$variant->id]['quantity'] > 99999999) {
                return ToolOutcome::denied('إجمالي كمية الصنف بعد دمج البنود أكبر من الحد المسموح.', auditable: false);
            }
            $unitString = number_format((float) $unit, 4, '.', '');
            $grouped[$variant->id]['total_cost'] = bcadd($grouped[$variant->id]['total_cost'] ?? '0', bcmul((string) (int) $raw['quantity'], $unitString, 8), 8);
        }

        $warnings = [];
        if (count($grouped) < count($rawItems)) {
            $warnings[] = 'بند مكرر اتدمج في سطر واحد قبل الاقتراح.';
        }

        $rows = [];
        $stockVersions = [];
        $totalValue = 0.0;
        foreach ($grouped as $g) {
            /** @var ProductVariant $variant */
            $variant = $g['variant'];
            $qty = $g['quantity'];
            $unit = (float) bcadd(bcdiv($g['total_cost'], (string) $qty, 5), '0.00005', 4);
            $totalValue += $qty * $unit;

            $stockVersions[$variant->id] = (int) StockMovement::where('product_variant_id', $variant->id)->max('id');
            $onHand = (string) StockLevel::where('product_variant_id', $variant->id)->sum(DB::raw('on_hand + in_transit'));
            $onHand = bccomp($onHand, '0', 4) < 0 ? '0' : $onHand;
            $costStr = (string) $variant->cost_price;
            $total = bcadd(bcmul($onHand, $costStr, 8), bcmul((string) $qty, (string) $unit, 8), 8);
            $denominator = bcadd($onHand, (string) $qty, 4);
            $newAvg = bccomp($denominator, '0', 4) === 0 ? $costStr : bcadd(bcdiv($total, $denominator, 5), '0.00005', 4);
            $pct = $variant->cost_price > 0 ? round(((float) $newAvg - (float) $variant->cost_price) / (float) $variant->cost_price * 100, 1) : null;

            // Flag cost variance anomalies exceeding +/-30% drift from last recorded unit cost.
            $reference = $variant->last_cost_price ?? $variant->cost_price;
            if ($reference !== null && (float) $reference > 0) {
                $drift = abs($unit - (float) $reference) / (float) $reference;
                if ($drift > 0.3) {
                    $last = (float) $reference;
                    $warnings[] = "تكلفة الوحدة في الدفعة دي ({$unit}) بعيدة ".round($drift * 100, 1)."% عن آخر استلام ({$last}) للصنف {$variant->sku} — اتأكد من الأصفار.";
                }
            } else {
                $warnings[] = "مفيش تكلفة مرجعية للصنف {$variant->sku}.";
            }

            $rows[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'quantity' => $qty,
                'unit_cost' => $unit,
                'cost_before' => (float) $variant->cost_price,
                'cost_after' => (float) $newAvg,
                'change_pct' => $pct,
                'on_hand_before' => (float) $onHand,
            ];
        }

        $threshold = (float) $this->settings->get('ai_inventory_high_risk_value', 50000);
        $highValue = $totalValue > $threshold;
        if ($highValue) {
            $warnings[] = "قيمة الدفعة {$totalValue} فوق العتبة {$threshold} — التأكيد بكتابة رقم الدفعة.";
        }

        $expected = [];
        foreach ($grouped as $id => $g) {
            $expected['variant:'.$id] = (int) $g['variant']->lock_version;
            $expected['stock:'.$id] = $stockVersions[$id];
        }

        // Use relative summary strings in LLM prompt while formatting detailed costs in preview card.
        $relative = array_map(fn ($r) => $r['sku'].': '.$r['cost_before'].' ← '.$r['cost_after'].($r['change_pct'] !== null ? ' ('.($r['change_pct'] >= 0 ? '+' : '').$r['change_pct'].'%)' : ''), $rows);

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'branch_id' => $scope['branch']->id,
                'location_id' => $args['location_id'] ?? null,
                'batch_number' => $batch,
                'notes' => $args['notes'] ?? null,
                'items' => array_map(fn ($r) => ['product_variant_id' => $r['variant_id'], 'quantity' => $r['quantity'], 'unit_cost' => $r['unit_cost']], $rows),
            ],
            risk: $highValue ? 'high' : 'medium',
            title: "استلام دفعة إنتاج {$batch}",
            before: ['batches' => [], 'note' => 'لا يوجد استلام بعد'],
            after: ['batches' => [$batch], 'changes' => $relative],
            warnings: $warnings,
            expectedVersions: $expected,
            typedConfirmation: $batch,
            typedConfirmationHint: 'رقم الدفعة',
            extraPreview: ['block_type' => 'cost_impact', 'rows' => $rows, 'batch_number' => $batch, 'total_value' => $totalValue],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $ids = array_column($action->args['items'] ?? [], 'product_variant_id');
        sort($ids);
        ProductVariant::whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get();
        Branch::whereKey((int) $action->args['branch_id'])->sharedLock()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        if (! CostAccess::canView($actor)) {
            throw new BusinessException('صلاحية رؤية التكلفة اتغيرت بعد الاقتراح.', [], 403);
        }
        $a = $action->args;
        foreach ($a['items'] as $item) {
            $variant = ProductVariant::find((int) $item['product_variant_id']);
            if (! $variant) {
                throw new BusinessException('أحد أصناف الدفعة لم يعد متاحًا.', [], 409);
            }
            if ((int) $variant->lock_version !== (int) ($action->expected_versions['variant:'.$variant->id] ?? -1)) {
                throw new BusinessException('حد عدّل بيانات صنف بعد الاقتراح. اطلب اقتراحًا جديدًا.', [], 409);
            }
            if ((int) StockMovement::where('product_variant_id', $variant->id)->max('id') !== (int) ($action->expected_versions['stock:'.$variant->id] ?? -1)) {
                throw new BusinessException('رصيد الصنف اتغير بعد معاينة متوسط التكلفة. اطلب معاينة جديدة قبل الاستلام.', [], 409);
            }
        }
        if (ProductionReceipt::whereRaw('LOWER(batch_number) = ?', [mb_strtolower((string) $a['batch_number'])])->exists()) {
            throw new BusinessException('رقم الدفعة اتسجل في أثناء المراجعة؛ راجع سجل الاستلام.', [], 409);
        }

        $receipt = $this->receipts->receive([
            'branch_id' => (int) $a['branch_id'],
            'location_id' => $a['location_id'] ?? null,
            'batch_number' => (string) $a['batch_number'],
            'notes' => $a['notes'] ?? null,
            'items' => array_map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'],
                'quantity' => (int) $i['quantity'],
                'unit_cost' => $i['unit_cost'],
            ], $a['items']),
        ], $actor->id, 'ai-'.$action->id);

        return ToolOutcome::result(true, "تم استلام دفعة الإنتاج {$receipt->batch_number}.", [
            'receipt_id' => $receipt->id,
            'batch_number' => $receipt->batch_number,
            'deep_link' => '#/production/'.$receipt->id,
        ]);
    }
}
