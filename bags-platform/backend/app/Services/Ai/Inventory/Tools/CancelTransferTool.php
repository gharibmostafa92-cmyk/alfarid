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

final class CancelTransferTool extends TransferToolBase implements WriteTool, AgentTool
{
    public function name(): string
    {
        return 'cancel_transfer';
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
        return ['transfers.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إلغاء تحويل مسودة أو معتمد (المعتمد بيفك الحجز تلقائيًا). المشحون لا يُلغى.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'transfer_id' => ['type' => 'INTEGER'],
                    'transfer_number' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
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
        if (! in_array($transfer->status, [TransferStatus::Draft, TransferStatus::Approved], true)) {
            return ToolOutcome::denied(
                "التحويل {$transfer->transfer_number} اتشحن، مش هينفع يتلغى. تحب أعمل استلام بكمية صفر وأسجله عجز، ولا أعمل تحويل عكسي؟",
                auditable: false,
            );
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['transfer_id' => $transfer->id],
            risk: $this->risk(),
            title: "إلغاء {$transfer->transfer_number}",
            before: ['status' => $transfer->status->value] + $this->itemsView($transfer),
            after: ['status' => TransferStatus::Cancelled->value],
            warnings: $transfer->status === TransferStatus::Approved ? ['الحجز هيتفك تلقائيًا.'] : [],
            expectedVersions: [$this->versionKey($transfer) => $transfer->version],
            extraPreview: ['block_type' => 'transfer_timeline'] + $this->timeline($transfer),
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $transfer = StockTransfer::findOrFail((int) $action->args['transfer_id']);
        $updated = $this->transfers->cancel($transfer, $actor->id);

        return ToolOutcome::result(true, "اتلغى {$updated->transfer_number}.", [
            'transfer_id' => $updated->id,
            'transfer_number' => $updated->transfer_number,
            'deep_link' => '#/transfers/'.$updated->id,
        ]);
    }
}
