<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\Inventory\TransferTimeline;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\TransferService;

final class GetTransferTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private TransferService $transfers,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_transfer';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['transfers.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تفاصيل تحويل واحد: المراحل الأربع والكميات (مطلوب، معتمد، مشحون، مستلم) وأي فرق.',
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

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $transfer = null;
        if (! empty($args['transfer_id'])) {
            $transfer = StockTransfer::find((int) $args['transfer_id']);
        } elseif (! empty($args['transfer_number'])) {
            $transfer = StockTransfer::whereRaw('LOWER(transfer_number) = LOWER(?)', [trim((string) $args['transfer_number'])])->first();
        } else {
            $options = $this->resolver->transfers($actor, (string) ($args['query'] ?? ''));
            if (count($options) !== 1) {
                return ToolOutcome::denied('حدد التحويل برقمه. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }
            $transfer = StockTransfer::find($options[0]['id']);
        }
        if (! $transfer) {
            return ToolOutcome::denied('التحويل مش موجود.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && (int) $transfer->from_branch_id !== $restricted && (int) $transfer->to_branch_id !== $restricted) {
            return ToolOutcome::denied('التحويل خارج نطاق فرعك.', auditable: false);
        }
        $transfer = $this->transfers->find($transfer->id);

        $items = $transfer->items->map(fn (StockTransferItem $i) => [
            'variant_id' => $i->product_variant_id,
            'sku' => $i->variant?->sku,
            'requested_qty' => (float) $i->requested_qty,
            'approved_qty' => $i->approved_qty !== null ? (float) $i->approved_qty : null,
            'shipped_qty' => $i->shipped_qty !== null ? (float) $i->shipped_qty : null,
            'received_qty' => $i->received_qty !== null ? (float) $i->received_qty : null,
            'shortage' => $i->shipped_qty !== null && $i->received_qty !== null ? round((float) $i->shipped_qty - (float) $i->received_qty, 4) : null,
        ])->values()->all();

        $timeline = TransferTimeline::build($transfer);

        return $this->presenter->present($actor, [
            'transfer' => [
                'id' => $transfer->id,
                'transfer_number' => $transfer->transfer_number,
                'from_branch' => $transfer->fromBranch?->name,
                'to_branch' => $transfer->toBranch?->name,
                'status' => $transfer->status->value,
                'notes' => $transfer->notes !== null ? $this->presenter->untrusted($transfer->notes, 200) : null,
            ],
            'items' => $items,
            'deep_link' => '#/transfers/'.$transfer->id,
        ], ['type' => 'transfer_timeline', 'stages' => $timeline, 'items' => $items, 'deep_link' => '#/transfers/'.$transfer->id]);
    }
}
