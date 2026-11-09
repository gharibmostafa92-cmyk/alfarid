<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\Inventory\TransferStatus;
use App\Models\AiPendingAction;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;

final class ReceiveTransferTool extends TransferToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'receive_transfer';
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
        return ['transfers.receive'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'استلام تحويل مشحون. الكمية لكل بند بين صفر والمشحون، والفرق يتسجل عجز تلقائيًا.',
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
        if ($transfer->status !== TransferStatus::Shipped) {
            return ToolOutcome::denied('الاستلام من المشحون بس (الحالة: '.$transfer->status->value.').', auditable: false);
        }
        $payload = $this->itemsPayload($transfer, $args['items'] ?? null, 'shipped_qty');
        if ($payload instanceof ToolOutcome) {
            return $payload;
        }

        $warnings = [];
        foreach ($transfer->items as $item) {
            $received = $payload[$item->id] ?? (float) $item->shipped_qty;
            $gap = round((float) $item->shipped_qty - $received, 4);
            if ($gap > 0) {
                $warnings[] = "عجز {$gap} في البند {$item->id} ({$item->variant?->sku}) — هيتسجل تلقائيًا.";
            }
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id, 'items' => array_map(fn ($id, $q) => ['item_id' => $id, 'quantity' => $q], array_keys($payload), $payload)],
            risk: $this->risk(),
            title: "استلام {$transfer->transfer_number}",
            before: ['status' => TransferStatus::Shipped->value] + $this->itemsView($transfer),
            after: ['status' => TransferStatus::Received->value, 'received' => $payload],
            warnings: $warnings,
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
        $updated = $this->transfers->receive($transfer, $payload, $actor->id, null, (int) ($action->expected_versions[$this->versionKey($transfer)] ?? 0));

        return ToolOutcome::result(true, "اتستلم {$updated->transfer_number}.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => '#/transfers/'.$updated->id,
        ]);
    }
}
