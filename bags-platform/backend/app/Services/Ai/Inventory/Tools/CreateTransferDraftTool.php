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

final class CreateTransferDraftTool extends TransferToolBase implements UndoableTool, WriteTool
{
    public function name(): string
    {
        return 'create_transfer_draft';
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
        return ['transfers.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء مسودة تحويل بين فرعين.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['from_branch_id', 'to_branch_id', 'items'],
                'properties' => (object) [
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
        $from = (int) ($args['from_branch_id'] ?? 0);
        $to = (int) ($args['to_branch_id'] ?? 0);
        if ($from < 1 || $to < 1) {
            return ToolOutcome::denied('حدد فرع الإرسال والاستلام.', auditable: false);
        }
        if ($from === $to) {
            return ToolOutcome::denied('فرع الإرسال لازم يختلف عن فرع الاستلام.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && $from !== $restricted && $to !== $restricted) {
            return ToolOutcome::denied('التحويل خارج نطاق فرعك.', auditable: false);
        }
        $items = $this->draftItems($actor, $args['items'] ?? null);
        if ($items instanceof ToolOutcome) {
            return $items;
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'from_branch_id' => $from,
                'to_branch_id' => $to,
                'notes' => $args['notes'] ?? null,
                'items' => array_map(fn ($i) => [
                    'product_variant_id' => $i['product_variant_id'],
                    'requested_qty' => $i['quantity'],
                    'from_location_id' => $i['from_location_id'],
                    'to_location_id' => $i['to_location_id'],
                ], $items),
            ],
            risk: $this->risk(),
            title: 'مسودة تحويل جديدة',
            before: ['status' => null],
            after: ['status' => TransferStatus::Draft->value, 'items_count' => count($items)],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        // لا صفوف بعد — القفل العام غير مستخدم لمخزون الأدوات.
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $a = $action->args;
        $transfer = $this->transfers->create([
            'from_branch_id' => (int) $a['from_branch_id'],
            'to_branch_id' => (int) $a['to_branch_id'],
            'notes' => $a['notes'] ?? null,
            'items' => array_map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'],
                'requested_qty' => $i['requested_qty'],
                'from_location_id' => $i['from_location_id'] ?? null,
                'to_location_id' => $i['to_location_id'] ?? null,
            ], $a['items']),
        ], $actor->id, 'ai-transfer:'.$action->id);
        $action->forceFill(['expected_versions' => array_merge($action->expected_versions ?? [], ['created_transfer_id' => $transfer->id])])->save();

        return ToolOutcome::result(true, "اتعملت مسودة التحويل {$transfer->transfer_number}.", [
            'transfer_id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'deep_link' => '#/transfers/'.$transfer->id,
        ]);
    }

    private function createdId(AiPendingAction $action): int
    {
        return (int) ($action->expected_versions['created_transfer_id'] ?? 0);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $t = StockTransfer::find($this->createdId($action));

        return $t !== null && $t->status === TransferStatus::Draft;
    }

    public function targetState(AiPendingAction $action): string
    {
        $t = StockTransfer::findOrFail($this->createdId($action));

        return hash('sha256', json_encode([$t->id, $t->version, $t->status->value], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $t = StockTransfer::findOrFail($this->createdId($action));
        $this->transfers->delete($t, $actor->id);

        return ToolOutcome::result(true, "اتمسحت مسودة التحويل {$t->transfer_number}.", []);
    }
}
