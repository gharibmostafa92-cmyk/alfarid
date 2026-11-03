<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\TransferStatus;
use App\Models\AiPendingAction;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;

final class ApproveTransferTool extends TransferToolBase implements UndoableTool, WriteTool, AgentTool
{
    public function name(): string
    {
        return 'approve_transfer';
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
        return ['transfers.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اعتماد تحويل وحجز المخزون في فرع الإرسال. الكمية لكل بند بين صفر والمطلوب.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => [
                        'type' => 'OBJECT',
                        'required' => ['item_id', 'quantity'],
                        'properties' => (object) [
                            'item_id' => ['type' => 'INTEGER'],
                            'quantity' => ['type' => 'NUMBER'],
                        ],
                    ]],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $resolved = $this->resolveTransfer($actor, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $transfer = $resolved['transfer'];
        if ($transfer->status !== TransferStatus::Draft) {
            return ToolOutcome::denied('الاعتماد من المسودة بس (الحالة: '.$transfer->status->value.').', auditable: false);
        }
        $payload = $this->itemsPayload($transfer, $args['items'] ?? null, 'requested_qty');
        if ($payload instanceof ToolOutcome) {
            return $payload;
        }
        if (array_sum($payload) <= 0) {
            return ToolOutcome::denied('اعتمد كمية موجبة على الأقل، أو ألغِ التحويل.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id, 'items' => array_map(fn ($id, $q) => ['item_id' => $id, 'quantity' => $q], array_keys($payload), $payload)],
            risk: $this->risk(),
            title: "اعتماد {$transfer->transfer_number}",
            before: ['status' => TransferStatus::Draft->value] + $this->itemsView($transfer),
            after: ['status' => TransferStatus::Approved->value, 'approved' => $payload],
            expectedVersions: [$this->versionKey($transfer) => $transfer->version],
            extraPreview: ['block_type' => 'transfer_timeline'] + $this->timeline($transfer),
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $transfer = StockTransfer::findOrFail((int) $a['transfer_id']);
        $payload = [];
        foreach ($a['items'] as $item) {
            $payload[(int) $item['item_id']] = $item['quantity'];
        }
        $updated = $this->transfers->approve($transfer, $payload, $actor->id, null, (int) ($action->expected_versions[$this->versionKey($transfer)] ?? 0));

        return ToolOutcome::result(true, "اتعتمد {$updated->transfer_number} واتحجز المخزون.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => '#/transfers/'.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $t = StockTransfer::find((int) $action->args['transfer_id']);

        return $t !== null && $t->status === TransferStatus::Approved;
    }

    public function targetState(AiPendingAction $action): string
    {
        $t = StockTransfer::findOrFail((int) $action->args['transfer_id']);

        return hash('sha256', json_encode([$t->id, $t->version, $t->status->value], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $t = StockTransfer::findOrFail((int) $action->args['transfer_id']);
        $this->transfers->cancel($t, $actor->id);

        return ToolOutcome::result(true, "اتلغى اعتماد {$t->transfer_number} واتفك الحجز.", []);
    }
}
