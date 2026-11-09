<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\StocktakeStatus;
use App\Models\AiPendingAction;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;

final class CancelStocktakeTool extends StocktakeToolBase implements WriteTool, AgentTool
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
        $transfer = $resolved['stocktake'];
        if (! in_array($transfer->status, [StocktakeStatus::Draft, StocktakeStatus::InProgress], true)) {
            return ToolOutcome::denied('الإلغاء من جلسة مفتوحة بس (الحالة: '.$transfer->status->value.').', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['stocktake_id' => $transfer->id],
            risk: $this->risk(),
            title: "إلغاء {$transfer->stocktake_number}",
            before: ['status' => $transfer->status->value],
            after: ['status' => StocktakeStatus::Cancelled->value],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $stocktake = Stocktake::findOrFail((int) $action->args['stocktake_id']);
        $updated = $this->stocktakes->update($stocktake, ['status' => StocktakeStatus::Cancelled->value], $actor->id);

        return ToolOutcome::result(true, "اتلغى {$updated->stocktake_number}.", [
            'stocktake_id' => $updated->id,
            'stocktake_number' => $updated->stocktake_number,
            'deep_link' => '#/stocktakes/'.$updated->id,
        ]);
    }
}
