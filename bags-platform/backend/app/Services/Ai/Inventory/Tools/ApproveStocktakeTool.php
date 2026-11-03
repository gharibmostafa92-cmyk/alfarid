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

final class ApproveStocktakeTool extends StocktakeToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'approve_stocktake';
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
        return ['stocktakes.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعتماد الجرد وتسجيل الفروقات في الدفتر. عالي الخطورة دائمًا والتأكيد بكتابة رقم الجرد. بلا تراجع.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'stocktake_id' => ['type' => 'INTEGER'],
                    'stocktake_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
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
        $stocktake = $this->stocktakes->find($resolved['stocktake']->id);
        if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return ToolOutcome::denied('الاعتماد من جلسة مفتوحة بس (الحالة: '.$stocktake->status->value.').', auditable: false);
        }
        if ($stocktake->items->isEmpty()) {
            return ToolOutcome::denied('الجرد بلا بنود.', auditable: false);
        }
        $uncounted = $stocktake->items->whereNull('counted_qty')->count();
        if ($uncounted > 0) {
            return ToolOutcome::denied("فيه {$uncounted} بنود لسه متعدّتش. كمّل العد الأول.", auditable: false);
        }

        $diffs = $stocktake->items->map(fn ($i) => [
            'sku' => $i->variant?->sku,
            'difference' => (float) ($i->difference ?? ((float) $i->counted_qty - (float) $i->system_qty)),
        ])->filter(fn ($d) => $d['difference'] != 0)->values()->all();

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['stocktake_id' => $stocktake->id],
            risk: $this->risk(),
            title: "اعتماد {$stocktake->stocktake_number}",
            before: ['status' => $stocktake->status->value],
            after: ['status' => StocktakeStatus::Approved->value, 'adjustments' => $diffs],
            warnings: $diffs === [] ? [] : ['الاعتماد هيسجل '.count($diffs).' فرق في الدفتر.'],
            typedConfirmation: $stocktake->stocktake_number,
            typedConfirmationHint: 'رقم الجرد',
            extraPreview: ['block_type' => 'count_sheet', 'rows' => $diffs],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $stocktake = Stocktake::findOrFail((int) $action->args['stocktake_id']);
        try {
            $updated = $this->stocktakes->approve($stocktake, $actor->id);
        } catch (BusinessException $e) {
            // سياسة الرفض: الأصناف المتغيرة تحتاج إعادة عد — checklist بدل الفشل الأعمى.
            $recount = $e->errors['recount_variant_ids'] ?? [];
            if ($recount === []) {
                throw $e;
            }
            $rows = array_map(fn ($id) => ['variant_id' => (int) $id, 'needs_recount' => true], (array) $recount);

            return ToolOutcome::result(false, $e->getMessage(), [
                'recount_variant_ids' => array_values(array_map('intval', (array) $recount)),
            ], ['type' => 'count_sheet', 'rows' => $rows]);
        }

        return ToolOutcome::result(true, "اتعتمد {$updated->stocktake_number} واتسجلت الفروقات.", [
            'stocktake_id' => $updated->id,
            'stocktake_number' => $updated->stocktake_number,
            'deep_link' => '#/stocktakes/'.$updated->id,
        ]);
    }
}
