<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\Catalog\PriceListType;
use App\Models\AiPendingAction;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\User;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingFingerprint;
use App\Services\Ai\Pricing\PricingLocks;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\CostAccess;
use App\Services\Pricing\PriceListService;
use App\Services\Shared\SettingService;
use App\Services\Auth\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class UpsertPriceListItemsTool implements WriteTool, LocksTargets, UndoableTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'upsert_price_list_items';
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
        return ['price_lists.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إضافة أو تعديل بنود داخل قائمة أسعار موجودة (سعر لكل متغير وحد كمية). مثال: "زوّد أسعار قائمة الموزعين 10%". دي قوائم أسعار — أما "زوّد سعر الشنط القطاعي" (السعر الأساسي على المتغير) فده bulk_update_prices.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id', 'rows'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT']],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['price_list_id'])) {
            throw ValidationException::withMessages(['price_list_id' => 'حدد القائمة.']);
        }
        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        $rawRows = (array) ($args['rows'] ?? []);
        if ($rawRows === []) {
            throw ValidationException::withMessages(['rows' => 'ابعت بند واحد على الأقل.']);
        }
        $maxItems = max(1, (int) $this->settings->get('ai_pricing_max_items_per_action', 200));
        if (count($rawRows) > $maxItems) {
            throw ValidationException::withMessages(['rows' => "عدد البنود (".count($rawRows).") أكبر من الحد المسموح ({$maxItems})."]);
        }

        $canViewCost = CostAccess::canView($actor);
        $belowCostPolicy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        $belowCostPolicy = in_array($belowCostPolicy, ['block', 'warn'], true) ? $belowCostPolicy : 'block';
        $now = Carbon::now();
        $listType = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
        $isWholesaleList = in_array($listType, [PriceListType::Wholesale->value, PriceListType::Distributor->value], true);

        $existing = PriceListItem::query()->where('price_list_id', $list->id)->get()->keyBy(fn (PriceListItem $i) => $i->product_variant_id.':'.number_format((float) $i->min_qty, 4, '.', ''));
        $existingById = PriceListItem::query()->where('price_list_id', $list->id)->get()->keyBy('id');

        // Dedupe repeated (variant, min_qty) rows: last wins.
        $deduped = [];
        $duplicates = 0;
        $order = [];
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['rows' => 'كل بند لازم يكون كائن فيه sku أو variant_id وسعر.']);
            }
            $variant = $this->resolveRowVariant($actor, $row);
            if ($variant instanceof ToolOutcome) {
                $deduped[] = ['_blocked' => true, 'row' => $row, 'reason' => $variant->message];
                continue;
            }
            $minQty = isset($row['min_qty']) && is_numeric($row['min_qty']) && (float) $row['min_qty'] > 0 ? (float) $row['min_qty'] : 1.0;
            $key = $variant->id.':'.number_format($minQty, 4, '.', '');
            if (array_key_exists($key, $order)) {
                $duplicates++;
            }
            $order[$key] = true;
            $deduped[$key] = ['variant' => $variant, 'min_qty' => $minQty, 'row' => $row];
        }
        $deduped = array_values($deduped);

        $toApply = [];
        $blocked = [];
        $warnings = [];
        if ($duplicates > 0) {
            $warnings[] = "{$duplicates} صفوف مكررة — اتاخد آخر سعر لكل شريحة.";
        }
        $expectedVersions = ['pl:'.$list->id => PricingFingerprint::priceList($list)];
        $uiRows = [];
        $minPct = null;

        foreach ($deduped as $entry) {
            if (isset($entry['_blocked'])) {
                $blocked[] = ['sku' => $entry['row']['sku'] ?? $entry['row']['variant_id'] ?? $entry['row']['query'] ?? '?', 'reason' => $entry['reason']];
                continue;
            }
            /** @var \App\Models\ProductVariant $variant */
            $variant = $entry['variant'];
            $minQty = $entry['min_qty'];
            $row = $entry['row'];
            $sku = $variant->sku;

            if (! isset($row['price']) || ! is_numeric($row['price'])) {
                $blocked[] = ['sku' => $sku, 'reason' => 'السعر مطلوب ورقم.'];
                continue;
            }
            $price = (float) $row['price'];
            if ($price <= 0) {
                $blocked[] = ['sku' => $sku, 'reason' => 'السعر الجديد لازم يكون أكبر من صفر.'];
                continue;
            }

            $startsAt = $this->optionalDate($row['starts_at'] ?? null, 'starts_at');
            $endsAt = $this->optionalDate($row['ends_at'] ?? null, 'ends_at');
            if ($startsAt === false || $endsAt === false) {
                $blocked[] = ['sku' => $sku, 'reason' => 'صيغة التاريخ غير صالحة.'];
                continue;
            }
            if ($startsAt !== null && $endsAt !== null && $endsAt->lt($startsAt)) {
                $blocked[] = ['sku' => $sku, 'reason' => 'نهاية الصلاحية تسبق بدايتها.'];
                continue;
            }

            $key = $variant->id.':'.number_format($minQty, 4, '.', '');
            $current = $existing->get($key);

            // Guard: wholesale list price must stay below the variant retail price.
            if ($isWholesaleList && $variant->retail_price !== null && $price >= (float) $variant->retail_price) {
                $blocked[] = ['sku' => $sku, 'reason' => "سعر الجملة ({$price}) هيبقى أكبر من أو يساوي القطاعي (".(float) $variant->retail_price.').'];
                continue;
            }

            // Guard: below cost, per ai_price_below_cost_policy.
            $cost = $variant->cost_price !== null ? (float) $variant->cost_price : null;
            if ($cost !== null && $cost > 0 && $price < $cost) {
                $message = $canViewCost
                    ? "السعر الجديد ({$price}) أقل من سعر التكلفة ({$cost})."
                    : 'السعر الجديد أقل من التكلفة (أسباب تسعير محمية).';
                if ($belowCostPolicy === 'block') {
                    $blocked[] = ['sku' => $sku, 'reason' => $message];
                    continue;
                }
                $warnings[] = "{$sku}: {$message}";
            }

            $old = $current ? (float) $current->price : null;
            $delta = $old !== null ? round($price - $old, 2) : $price;
            $pct = $old !== null && $old > 0 ? round((($price - $old) / $old) * 100, 2) : null;
            if ($pct !== null && ($minPct === null || $pct < $minPct)) {
                $minPct = $pct;
            }
            if ($current) {
                $expectedVersions['pli:'.$current->id] = PricingFingerprint::item($current);
            }

            $toApply[] = [
                'variant_id' => $variant->id,
                'min_qty' => $minQty,
                'price' => $price,
                'starts_at' => $startsAt?->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'existing_item_id' => $current?->id,
            ];
            $uiRows[] = [
                'sku' => $sku,
                'min_qty' => $minQty,
                'old_price' => $old,
                'new_price' => $price,
                'delta' => $delta,
                'delta_pct' => $pct,
            ];
        }

        // Resolve all competing price-list rows in one query instead of one query per imported line.
        $shadowWarnings = $this->shadowWarnings($toApply, $list);
        foreach ($toApply as $row) {
            $key = (int) $row['variant_id'].':'.number_format((float) $row['min_qty'], 4, '.', '');
            if (isset($shadowWarnings[$key])) {
                $warnings[] = '#'.$row['variant_id'].': '.$shadowWarnings[$key];
            }
        }

        if ($toApply === []) {
            throw ValidationException::withMessages(['rows' => 'كل البنود تم استبعادها بواسطة الحراس.']);
        }

        $risk = (count($toApply) > 20 || ($minPct !== null && $minPct < -15)) ? 'high' : 'medium';
        $inverse = ['updated' => [], 'created' => []];
        foreach ($toApply as $row) {
            if ($row['existing_item_id'] !== null) {
                $current = $existingById->get($row['existing_item_id']);
                if ($current) {
                    $inverse['updated'][(string) $current->id] = [
                        'product_variant_id' => (int) $current->product_variant_id,
                        'min_qty' => (float) $current->min_qty,
                        'price' => (float) $current->price,
                        'starts_at' => $current->starts_at?->format('Y-m-d H:i:s'),
                        'ends_at' => $current->ends_at?->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        $summary = [
            'list_id' => $list->id,
            'list_name' => $list->name,
            'updated_count' => count(array_filter($toApply, fn ($r) => $r['existing_item_id'] !== null)),
            'created_count' => count(array_filter($toApply, fn ($r) => $r['existing_item_id'] === null)),
            'blocked_count' => count($blocked),
        ];

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['price_list_id' => $list->id, 'rows' => $toApply],
            risk: $risk,
            title: 'تعديل بنود قائمة الأسعار: '.$list->name.' ('.count($toApply).' بند)',
            before: ['items_count' => $existingById->count()],
            after: $summary,
            warnings: $warnings,
            expectedVersions: $expectedVersions,
            inverse: $inverse,
            typedConfirmation: $risk === 'high' ? $list->name : null,
            typedConfirmationHint: 'اكتب اسم القائمة',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => $summary,
                'rows' => $uiRows,
                'blocked' => $blocked,
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        $listId = (int) $action->args['price_list_id'];
        $itemIds = collect((array) ($action->args['rows'] ?? []))->map(fn ($r) => $r['existing_item_id'] ?? null)->filter()->map('intval')->values()->all();
        if ($action->status === 'executed') {
            $inverse = (array) ($action->inverse ?? []);
            $itemIds = array_values(array_unique(array_merge(
                $itemIds,
                array_map('intval', array_keys((array) ($inverse['updated'] ?? []))),
                array_map('intval', (array) ($inverse['created'] ?? [])),
            )));
        }
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
        $rows = collect((array) ($action->args['rows'] ?? []))->sortBy(fn ($r) => $r['existing_item_id'] ?? PHP_INT_MAX)->values()->all();

        $createdIds = [];
        app(CatalogRetailPriceObserver::class)->batch(function () use ($actor, $listId, $rows, &$createdIds): void {
            $list = PriceList::query()->findOrFail($listId);
            foreach ($rows as $row) {
                $data = [
                    'product_variant_id' => (int) $row['variant_id'],
                    'min_qty' => (float) $row['min_qty'],
                    'price' => (float) $row['price'],
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                ];
                if (! empty($row['existing_item_id'])) {
                    $item = PriceListItem::query()->findOrFail((int) $row['existing_item_id']);
                    $this->lists->updateItem($item, $data, $actor);
                } else {
                    $created = $this->lists->createItem($list, $data, $actor);
                    $createdIds[] = (int) $created->id;
                }
            }
        });

        if ($createdIds !== []) {
            $inverse = (array) ($action->inverse ?? []);
            $inverse['created'] = $createdIds;
            $action->forceFill(['inverse' => $inverse])->save();
        }

        $updated = count(array_filter($rows, fn ($r) => ! empty($r['existing_item_id'])));
        StaffAdministration::audit(
            $actor,
            'price_list.items_upserted',
            'price_list',
            $listId,
            ['count'],
            [],
            ['count' => count($rows)],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$listId, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم تحديث '.count($rows)." بند (تعديل: {$updated}، جديد: ".count($createdIds).').', [
            'price_list_id' => $listId,
            'updated_count' => $updated,
            'created_count' => count($createdIds),
            'deep_link' => '#/pricing?list='.$listId,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        $inverse = $action->inverse ?? [];

        return is_array($inverse) && (! empty($inverse['updated']) || ! empty($inverse['created']));
    }

    public function targetState(AiPendingAction $action): string
    {
        $ids = [];
        foreach ((array) ($action->args['rows'] ?? []) as $row) {
            if (! empty($row['existing_item_id'])) {
                $ids[] = (int) $row['existing_item_id'];
            }
        }
        $inverse = (array) ($action->inverse ?? []);
        foreach ((array) ($inverse['created'] ?? []) as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        $state = PriceListItem::query()->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'product_variant_id', 'price', 'min_qty', 'starts_at', 'ends_at'])
            ->map(fn (PriceListItem $i) => [
                (int) $i->getKey(),
                (int) $i->getRawOriginal('product_variant_id'),
                (string) $i->getRawOriginal('price'),
                (string) $i->getRawOriginal('min_qty'),
                $i->getRawOriginal('starts_at'),
                $i->getRawOriginal('ends_at'),
            ])->all();

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $listId = (int) $action->args['price_list_id'];
        $inverse = (array) ($action->inverse ?? []);
        $lockIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys((array) ($inverse['updated'] ?? []))),
            array_map('intval', (array) ($inverse['created'] ?? [])),
        )));
        PricingLocks::lockListAndItems($action, $listId, $lockIds, '');
        app(CatalogRetailPriceObserver::class)->batch(function () use ($actor, $inverse): void {
            foreach ((array) ($inverse['updated'] ?? []) as $id => $old) {
                $item = PriceListItem::query()->findOrFail((int) $id);
                $this->lists->updateItem($item, [
                    'product_variant_id' => (int) $old['product_variant_id'],
                    'min_qty' => (float) $old['min_qty'],
                    'price' => (float) $old['price'],
                    'starts_at' => $old['starts_at'] ?? null,
                    'ends_at' => $old['ends_at'] ?? null,
                ], $actor);
            }
            $created = array_map('intval', (array) ($inverse['created'] ?? []));
            sort($created);
            foreach ($created as $id) {
                $item = PriceListItem::query()->find($id);
                if ($item) {
                    $this->lists->deleteItem($item, $actor);
                }
            }
        });

        return ToolOutcome::result(true, 'تم التراجع عن تعديل بنود القائمة.', [
            'price_list_id' => (int) $action->args['price_list_id'],
            'deep_link' => '#/pricing?list='.(int) $action->args['price_list_id'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return \App\Models\ProductVariant|ToolOutcome
     */
    private function resolveRowVariant(User $actor, array $row): mixed
    {
        if (! empty($row['variant_id'])) {
            $variant = \App\Models\ProductVariant::query()->find((int) $row['variant_id']);
            if (! $variant) {
                return ToolOutcome::denied('المتغير رقم '.$row['variant_id'].' مش موجود.', auditable: false);
            }

            return $variant;
        }
        $code = trim((string) ($row['sku'] ?? ''));
        if ($code !== '') {
            $variant = $this->resolver->exactVariantByCode($code);
            if (! $variant) {
                return ToolOutcome::denied("مش لاقي متغير بالكود {$code}.", auditable: false);
            }

            return $variant;
        }
        $query = trim((string) ($row['query'] ?? ''));
        if ($query === '') {
            return ToolOutcome::denied('كل بند محتاج variant_id أو sku أو query.', auditable: false);
        }
        // Variant matching never guesses: exactly one match or blocked.
        $options = $this->resolver->variants($actor, $query);
        if (count($options) !== 1) {
            return ToolOutcome::denied("الصف «{$query}» غامض — حدده بـ sku.", auditable: false);
        }
        $variant = \App\Models\ProductVariant::query()->find($options[0]['id']);
        if (! $variant) {
            return ToolOutcome::denied('المتغير مش موجود.', auditable: false);
        }

        return $variant;
    }

    private function optionalDate(mixed $value, string $field): Carbon|bool|null
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim((string) $value));
        } catch (\Throwable) {
            return false;
        }
    }

    private function shadowWarnings(array $rows, PriceList $target): array
    {
        if ($rows === []) {
            return [];
        }
        $now = Carbon::now();
        $variantIds = array_values(array_unique(array_map('intval', array_column($rows, 'variant_id'))));
        $maxQty = max(array_map('floatval', array_column($rows, 'min_qty')));
        $targetType = $target->type instanceof PriceListType ? $target->type->value : (string) $target->type;
        $candidates = PriceListItem::query()
            ->join('price_lists', 'price_lists.id', '=', 'price_list_items.price_list_id')
            ->where('price_lists.is_active', true)
            ->where('price_lists.type', $targetType)
            ->when($targetType === PriceListType::CustomerSpecific->value,
                fn ($q) => $q->where('price_lists.customer_id', $target->customer_id))
            ->when($targetType === PriceListType::CustomerGroup->value,
                fn ($q) => $q->where('price_lists.customer_group_id', $target->customer_group_id))
            ->whereIn('price_list_items.product_variant_id', $variantIds)
            ->where('price_list_items.min_qty', '<=', $maxQty)
            ->where(fn ($q) => $q->whereNull('price_list_items.starts_at')->orWhere('price_list_items.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_list_items.ends_at')->orWhere('price_list_items.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.starts_at')->orWhere('price_lists.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.ends_at')->orWhere('price_lists.ends_at', '>=', $now))
            ->where(fn ($q) => $q->whereNull('price_lists.branch_id')->orWhere('price_lists.branch_id', $target->branch_id))
            ->orderBy('price_list_items.product_variant_id')
            ->orderByRaw('price_lists.branch_id IS NULL ASC')
            ->orderByDesc('price_list_items.min_qty')
            ->orderByDesc('price_lists.priority')
            ->orderBy('price_list_items.price')
            ->orderBy('price_list_items.id')
            ->get([
                'price_list_items.product_variant_id', 'price_list_items.min_qty', 'price_list_items.price_list_id',
                'price_list_items.id', 'price_lists.name', 'price_lists.branch_id', 'price_lists.priority',
            ])->groupBy('product_variant_id');

        $warnings = [];
        foreach ($rows as $row) {
            $variantId = (int) $row['variant_id'];
            $minQty = (float) $row['min_qty'];
            $winner = $candidates->get($variantId, collect())
                ->first(fn (PriceListItem $item) => (float) $item->min_qty <= $minQty);
            if ($winner !== null && (int) $winner->price_list_id !== (int) $target->id) {
                $key = $variantId.':'.number_format($minQty, 4, '.', '');
                $warnings[$key] = "البند ده مش هيظهر لحد لأن قائمة '{$winner->name}' أولويتها أعلى.";
            }
        }

        return $warnings;
    }
}
