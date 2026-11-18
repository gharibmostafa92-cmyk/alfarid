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
use Illuminate\Support\Facades\DB;

final class CancelStocktakeTool extends StocktakeToolBase implements LocksTargets, WriteTool, AgentTool
{
    public function name(): string
    {
        return 'cancel_stocktake';
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
        return ['stocktakes.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إلغاء جلسة جرد مفتوحة.',
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
        $stocktake = $resolved['stocktake'];
        if (! in_array($stocktake->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return ToolOutcome::denied('الإلغاء من جلسة مفتوحة بس (الحالة: '.$stocktake->status->value.').', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['stocktake_id' => $stocktake->id],
            risk: $this->risk(),
            title: "إلغاء {$stocktake->stocktake_number}",
            before: ['status' => $stocktake->status->value],
            after: ['status' => StocktakeStatus::Cancelled->value],
            expectedVersions: ['stocktake:'.$stocktake->id => $stocktake->status->value],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        Stocktake::query()->whereKey((int) ($action->args['stocktake_id'] ?? 0))->lockForUpdate()->first();
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $updated = DB::transaction(function () use ($action, $actor): Stocktake {
            $stocktake = Stocktake::query()->lockForUpdate()->findOrFail((int) $action->args['stocktake_id']);
            $expected = $action->expected_versions['stocktake:'.$stocktake->id] ?? null;
            if ($expected !== null && $stocktake->status->value !== $expected) {
                throw new BusinessException('حالة الجرد تغيّرت أثناء المراجعة؛ اطلب الاقتراح من جديد.', [], 409);
            }

            return $this->stocktakes->update($stocktake, ['status' => StocktakeStatus::Cancelled->value], $actor->id);
        });

        return ToolOutcome::result(true, "اتلغى {$updated->stocktake_number}.", [
            'stocktake_id' => $updated->id,
            'stocktake_number' => $updated->stocktake_number,
            'deep_link' => '#/stocktakes/'.$updated->id,
        ]);
    }
}
