<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Enums\TransferStatus;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;

final class UpdateTransferDraftTool extends TransferToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'update_transfer_draft';
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
        return ['transfers.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل مسودة تحويل (الفروع والبنود والملاحظات). المسودة فقط.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'from_branch_id' => ['type' => 'INTEGER'],
                    'to_branch_id' => ['type' => 'INTEGER'],
                    'notes' => ['type' => 'STRING'],
                    'items' => ['type' => 'ARRAY', 'items' => [
                        'type' => 'OBJECT',
                        'required' => ['quantity'],
                        'properties' => (object) [
                            'variant_id' => ['type' => 'INTEGER'],
                            'product_variant_id' => ['type' => 'INTEGER'],
                            'sku' => ['type' => 'STRING'],
                            'query' => ['type' => 'STRING'],
                            'quantity' => ['type' => 'INTEGER'],
                            'from_location_id' => ['type' => 'INTEGER'],
                            'to_location_id' => ['type' => 'INTEGER'],
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
            return ToolOutcome::denied('التعديل متاح على المسودة بس (الحالة: '.$transfer->status->value.').', auditable: false);
        }

        $data = [];
        if (isset($args['from_branch_id'])) {
            $data['from_branch_id'] = (int) $args['from_branch_id'];
        }
        if (isset($args['to_branch_id'])) {
            $data['to_branch_id'] = (int) $args['to_branch_id'];
        }
        if (array_key_exists('notes', $args)) {
            $data['notes'] = $args['notes'];
        }
        if (array_key_exists('items', $args)) {
            $items = $this->draftItems($actor, $args['items']);
            if ($items instanceof ToolOutcome) {
                return $items;
            }
            $data['items'] = array_map(fn ($i) => [
                'product_variant_id' => $i['product_variant_id'],
                'requested_qty' => $i['quantity'],
                'from_location_id' => $i['from_location_id'],
                'to_location_id' => $i['to_location_id'],
            ], $items);
        }
        if ($data === []) {
            return ToolOutcome::denied('مفيش حاجة تتعدل.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            array_merge(['transfer_id' => $transfer->id], $data),
            risk: $this->risk(),
            title: "تعديل مسودة {$transfer->transfer_number}",
            before: ['status' => $transfer->status->value] + $this->itemsView($transfer),
            after: ['status' => TransferStatus::Draft->value] + (isset($data['items']) ? ['items' => $data['items']] : $this->itemsView($transfer)),
            expectedVersions: [$this->versionKey($transfer) => $transfer->version],
            extraPreview: ['block_type' => 'transfer_timeline'] + $this->timeline($transfer->fresh()),
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $transfer = \App\Models\StockTransfer::findOrFail((int) $a['transfer_id']);
        $data = array_intersect_key($a, array_flip(['from_branch_id', 'to_branch_id', 'notes', 'items']));
        unset($data['transfer_id']);
        $updated = $this->transfers->update($transfer, $data, $actor->id);

        return ToolOutcome::result(true, "اتعدلت مسودة {$updated->transfer_number}.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => '#/transfers/'.$updated->id,
        ]);
    }
}
