<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\AiPendingAction;
use App\Models\PriceListItem;
use App\Models\User;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingFingerprint;
use App\Services\Ai\Pricing\PricingLocks;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\PriceListService;
use App\Services\StaffAdministration;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class DeletePriceListItemsTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'delete_price_list_items';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['price_lists.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف بنود محددة من قائمة أسعار (تراجع متاح خلال 15 دقيقة).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id', 'item_ids'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'item_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['price_list_id']) || empty($args['item_ids'])) {
            throw ValidationException::withMessages(['item_ids' => 'حدد القائمة والبنود المطلوب حذفها.']);
        }
        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        $ids = array_values(array_unique(array_map('intval', (array) $args['item_ids'])));
        $items = PriceListItem::query()->where('price_list_id', $list->id)->whereIn('id', $ids)->with('variant:product_variants.id,sku')->get()->keyBy('id');
        $missing = array_diff($ids, $items->keys()->map('intval')->all());
        if ($missing !== []) {
            throw ValidationException::withMessages(['item_ids' => 'بنود مش موجودة في القائمة دي: '.implode('، ', $missing).'.']);
        }

        $expectedVersions = ['pl:'.$list->id => PricingFingerprint::priceList($list)];
        $inverse = [];
        $rows = [];
        foreach ($items->sortKeys() as $item) {
            $expectedVersions['pli:'.$item->id] = PricingFingerprint::item($item);
            $inverse[(string) $item->id] = [
                'product_variant_id' => (int) $item->product_variant_id,
                'min_qty' => (float) $item->min_qty,
                'price' => (float) $item->price,
                'starts_at' => $item->starts_at?->format('Y-m-d H:i:s'),
                'ends_at' => $item->ends_at?->format('Y-m-d H:i:s'),
            ];
            $rows[] = ['sku' => $item->variant?->sku ?? ('#'.$item->product_variant_id), 'min_qty' => (float) $item->min_qty, 'price' => (float) $item->price];
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['price_list_id' => $list->id, 'item_ids' => $ids],
            risk: $this->risk(),
            title: 'حذف '.count($ids).' بنود من قائمة الأسعار: '.$list->name,
            before: ['items_count' => $list->items_count],
            after: ['items_count' => max(0, (int) $list->items_count - count($ids))],
            expectedVersions: $expectedVersions,
            inverse: ['items' => $inverse],
            typedConfirmation: $list->name,
            typedConfirmationHint: 'اكتب اسم القائمة',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => ['list_id' => $list->id, 'list_name' => $list->name, 'deleted_count' => count($ids)],
                'rows' => $rows,
                'blocked' => [],
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $listId = (int) $action->args['price_list_id'];
        $itemIds = array_map('intval', (array) ($action->args['item_ids'] ?? []));
        $expectedPlis = [];
        foreach ((array) ($action->expected_versions ?? []) as $key => $version) {
            if (is_string($key) && str_starts_with($key, 'pli:')) {
                $expectedPlis[$key] = (string) $version;
            }
        }
        PricingLocks::lockListAndItems($action, $listId, $itemIds, (string) ($action->expected_versions['pl:'.$listId] ?? ''), $expectedPlis);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $listId = (int) $action->args['price_list_id'];
        $ids = array_map('intval', (array) ($action->args['item_ids'] ?? []));
        sort($ids);

        app(CatalogRetailPriceObserver::class)->batch(function () use ($actor, $listId, $ids): void {
            foreach ($ids as $id) {
                $item = PriceListItem::query()->where('price_list_id', $listId)->findOrFail($id);
                $this->lists->deleteItem($item, $actor);
            }
        });

        StaffAdministration::audit(
            $actor,
            'price_list.items_deleted',
            'price_list',
            $listId,
            ['count'],
            ['count' => count($ids)],
            ['count' => 0],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$listId, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم حذف '.count($ids).' بنود من القائمة.', [
            'price_list_id' => $listId,
            'deleted_count' => count($ids),
            'deep_link' => '#/pricing?list='.$listId,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $inverse = $action->inverse ?? [];

        return is_array($inverse) && ! empty($inverse['items']);
    }

    public function targetState(AiPendingAction $action): string
    {
        $state = PriceListItem::query()->where('price_list_id', (int) $action->args['price_list_id'])
            ->orderBy('id')->get(['id', 'price', 'min_qty'])
            ->map(fn (PriceListItem $i) => [(int) $i->id, round((float) $i->price, 2), round((float) $i->min_qty, 4)])->all();

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $listId = (int) $action->args['price_list_id'];
        $items = (array) (($action->inverse ?? [])['items'] ?? []);

        app(CatalogRetailPriceObserver::class)->batch(function () use ($actor, $listId, $items): void {
            $list = \App\Models\PriceList::query()->findOrFail($listId);
            foreach ($items as $old) {
                $this->lists->createItem($list->fresh(), [
                    'product_variant_id' => (int) $old['product_variant_id'],
                    'min_qty' => (float) $old['min_qty'],
                    'price' => (float) $old['price'],
                    'starts_at' => $old['starts_at'] ?? null,
                    'ends_at' => $old['ends_at'] ?? null,
                ], $actor);
            }
        });

        return ToolOutcome::result(true, 'تم التراجع عن حذف البنود.', [
            'price_list_id' => $listId,
            'deep_link' => '#/pricing?list='.$listId,
        ]);
    }
}
