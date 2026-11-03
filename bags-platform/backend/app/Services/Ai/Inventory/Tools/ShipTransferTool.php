<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\TransferStatus;
use App\Models\AiPendingAction;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;

final class ShipTransferTool extends TransferToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'ship_transfer';
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
        return ['transfers.ship'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'شحن تحويل معتمد. المشحون لا يُلغى.',
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
        if ($transfer->status !== TransferStatus::Approved) {
            return ToolOutcome::denied('الشحن من المعتمد بس (الحالة: '.$transfer->status->value.').', auditable: false);
        }
        $payload = $this->itemsPayload($transfer, $args['items'] ?? null, 'approved_qty');
        if ($payload instanceof ToolOutcome) {
            return $payload;
        }
        if (array_sum($payload) <= 0) {
            return ToolOutcome::denied('اشحن كمية موجبة على الأقل، أو ألغِ التحويل لفك الحجز.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id, 'items' => array_map(fn ($id, $q) => ['item_id' => $id, 'quantity' => $q], array_keys($payload), $payload)],
            risk: $this->risk(),
            title: "شحن {$transfer->transfer_number}",
            before: ['status' => TransferStatus::Approved->value] + $this->itemsView($transfer),
            after: ['status' => TransferStatus::Shipped->value, 'shipped' => $payload],
            warnings: ['الشحنة اللي اتشحنت مابتتلغيش.'],
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
        $updated = $this->transfers->ship($transfer, $payload, $actor->id, null, (int) ($action->expected_versions[$this->versionKey($transfer)] ?? 0));

        return ToolOutcome::result(true, "اتشحن {$updated->transfer_number}.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => '#/transfers/'.$updated->id,
        ]);
    }
}
