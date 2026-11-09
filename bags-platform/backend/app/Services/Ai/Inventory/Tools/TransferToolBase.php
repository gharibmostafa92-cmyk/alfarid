<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\TransferTimeline;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\DiscreteQuantity;
use App\Services\Inventory\TransferService;

abstract class TransferToolBase implements LocksTargets
{
    public function __construct(
        protected EntityResolver $resolver,
        protected BranchAccess $branches,
        protected TransferService $transfers,
        protected ProposalBuilder $proposals,
    ) {}

    /**
     * @param  array<string,mixed>  $args
     * @return array{transfer: StockTransfer}|ToolOutcome
     */
    protected function resolveTransfer(User $actor, array $args): array|ToolOutcome
    {
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

        return ['transfer' => $transfer->loadMissing(['items.variant', 'fromBranch', 'toBranch'])];
    }

    /**
     * @param  list<array{item_id?:int,quantity?:mixed}>  $rawItems
     * @return array<int,float>|ToolOutcome item_id => quantity
     */
    protected function itemsPayload(StockTransfer $transfer, ?array $rawItems, string $capField): array|ToolOutcome
    {
        $byId = $transfer->items->keyBy('id');
        if ($rawItems === null) {
            $out = [];
            foreach ($byId as $id => $item) {
                $out[$id] = (float) $item->{$capField};
            }

            return $out;
        }
        if ($rawItems === [] || count($rawItems) > 200) {
            return ToolOutcome::denied('حدد بندًا واحدًا على الأقل.', auditable: false);
        }
        $out = [];
        foreach ($rawItems as $raw) {
            $id = (int) ($raw['item_id'] ?? 0);
            $item = $byId->get($id);
            if (! $item) {
                return ToolOutcome::denied("البند {$id} مش تابع للتحويل ده.", auditable: false);
            }
            try {
                DiscreteQuantity::assert($raw['quantity'] ?? null, allowZero: true);
            } catch (BusinessException $e) {
                return ToolOutcome::denied($e->getMessage(), auditable: false);
            }
            $qty = (float) $raw['quantity'];
            $cap = (float) $item->{$capField};
            if ($qty < 0 || $qty > $cap) {
                return ToolOutcome::denied("كمية البند {$id} لازم تكون بين صفر و{$cap}.", auditable: false);
            }
            $out[$id] = $qty;
        }

        return $out;
    }

    /**
     * @param  list<array{variant_id?:int,sku?:string,query?:string,quantity?:mixed,from_location_id?:int,to_location_id?:int}>  $rawItems
     * @return list<array{product_variant_id:int,quantity:float,from_location_id:?int,to_location_id:?int}>|ToolOutcome
     */
    protected function draftItems(User $actor, ?array $rawItems): array|ToolOutcome
    {
        if (! is_array($rawItems) || $rawItems === [] || count($rawItems) > 100) {
            return ToolOutcome::denied('حدد بندًا واحدًا على الأقل (بحد أقصى 100).', auditable: false);
        }
        $out = [];
        $seen = [];
        foreach ($rawItems as $i => $raw) {
            if (! is_array($raw)) {
                return ToolOutcome::denied('بند رقم '.($i + 1).' غير سليم.', auditable: false);
            }
            $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, [
                'variant_id' => $raw['product_variant_id'] ?? $raw['variant_id'] ?? null,
                'sku' => $raw['sku'] ?? null,
                'query' => $raw['query'] ?? null,
            ]);
            if ($resolved instanceof ToolOutcome) {
                return $resolved;
            }
            try {
                DiscreteQuantity::assert($raw['quantity'] ?? null);
            } catch (BusinessException $e) {
                return ToolOutcome::denied($e->getMessage(), auditable: false);
            }
            $item = [
                'product_variant_id' => $resolved['variant']->id,
                'quantity' => (float) $raw['quantity'],
                'from_location_id' => isset($raw['from_location_id']) ? (int) $raw['from_location_id'] : null,
                'to_location_id' => isset($raw['to_location_id']) ? (int) $raw['to_location_id'] : null,
            ];
            $dup = $item['product_variant_id'].':'.($item['from_location_id'] ?? 0).':'.($item['to_location_id'] ?? 0);
            if (isset($seen[$dup])) {
                return ToolOutcome::denied('بند مكرر في نفس التحويل.', auditable: false);
            }
            $seen[$dup] = true;
            $out[] = $item;
        }

        return $out;
    }

    protected function versionKey(StockTransfer $transfer): string
    {
        return 'transfer:'.$transfer->id;
    }

    /** @return array{stages:list<array<string,mixed>>} */
    protected function timeline(StockTransfer $transfer): array
    {
        return ['deep_link' => '#/transfers/'.$transfer->id, ...$this->itemsView($transfer), 'stages' => TransferTimeline::build($transfer)];
    }

    /** @return array{items:list<array<string,mixed>>} */
    protected function itemsView(StockTransfer $transfer): array
    {
        return ['items' => $transfer->items->map(fn ($i) => [
            'item_id' => $i->id,
            'variant_id' => $i->product_variant_id,
            'sku' => $i->variant?->sku,
            'requested_qty' => (float) $i->requested_qty,
            'approved_qty' => $i->approved_qty !== null ? (float) $i->approved_qty : null,
            'shipped_qty' => $i->shipped_qty !== null ? (float) $i->shipped_qty : null,
            'received_qty' => $i->received_qty !== null ? (float) $i->received_qty : null,
        ])->values()->all()];
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $transfer = StockTransfer::query()->whereKey((int) ($action->args['transfer_id'] ?? 0))->lockForUpdate()->first();
        if ($action->status === 'pending' && $transfer && (int) ($action->expected_versions[$this->versionKey($transfer)] ?? 0) !== (int) $transfer->version) {
            throw new BusinessException('التحويل اتعدل بعد الاقتراح؛ اطلب اقتراحًا جديدًا.', [], 409);
        }
    }
}
