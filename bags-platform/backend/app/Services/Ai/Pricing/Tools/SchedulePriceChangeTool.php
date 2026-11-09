<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\PriceListType;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
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

final class SchedulePriceChangeTool implements WriteTool
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
        return 'schedule_price_change';
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
        return ['price_lists.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'جدولة تغيير أسعار مستقبلي: قائمة جديدة بنفس بنود قائمة حالية لكن بأسعار معدلة وبداية مستقبلية وأولوية أعلى — بدل تعديل الحالية. النمط الآمن لزيادة الأسعار.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['source_price_list_id', 'rows', 'starts_at'],
                'properties' => (object) [
                    'source_price_list_id' => ['type' => 'INTEGER'],
                    'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT']],
                    'starts_at' => ['type' => 'STRING'],
                    'name' => ['type' => 'STRING'],
                    'priority' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['source_price_list_id'])) {
            throw ValidationException::withMessages(['source_price_list_id' => 'حدد القائمة المصدر.']);
        }
        try {
            $source = $this->lists->find((int) $args['source_price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار المصدر غير موجودة.', auditable: false);
        }

        $startsAtRaw = trim((string) ($args['starts_at'] ?? ''));
        if ($startsAtRaw === '') {
            throw ValidationException::withMessages(['starts_at' => 'حدد تاريخ البداية المستقبلية.']);
        }
        try {
            $startsAt = Carbon::parse($startsAtRaw);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }
        if (! $startsAt->isFuture()) {
            throw ValidationException::withMessages(['starts_at' => 'الجدولة لازم تكون بتاريخ مستقبلي.']);
        }

        $rawRows = (array) ($args['rows'] ?? []);
        if ($rawRows === []) {
            throw ValidationException::withMessages(['rows' => 'ابعت بند واحد على الأقل.']);
        }
        $maxItems = max(1, (int) $this->settings->get('ai_pricing_max_items_per_action', 200));
        if (count($rawRows) > $maxItems) {
            throw ValidationException::withMessages(['rows' => "عدد البنود (".count($rawRows).") أكبر من الحد ({$maxItems})."]);
        }

        $canViewCost = CostAccess::canView($actor);
        $belowCostPolicy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        $belowCostPolicy = in_array($belowCostPolicy, ['block', 'warn'], true) ? $belowCostPolicy : 'block';
        $sourceType = $source->type instanceof PriceListType ? $source->type->value : (string) $source->type;
        $isWholesaleList = in_array($sourceType, [PriceListType::Wholesale->value, PriceListType::Distributor->value], true);

        $sourceItems = \App\Models\PriceListItem::query()->where('price_list_id', $source->id)->with('variant:id,sku,retail_price,cost_price')->get()
            ->keyBy(fn ($i) => $i->product_variant_id.':'.number_format((float) $i->min_qty, 4, '.', ''));

        $rows = [];
        $blocked = [];
        $warnings = [];
        foreach ($rawRows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['rows' => 'كل بند لازم يكون كائن.']);
            }
            $variant = null;
            if (! empty($row['variant_id'])) {
                $variant = \App\Models\ProductVariant::query()->find((int) $row['variant_id']);
            } elseif (! empty($row['sku'])) {
                $variant = $this->resolver->exactVariantByCode(trim((string) $row['sku']));
            }
            if (! $variant) {
                $blocked[] = ['sku' => $row['sku'] ?? $row['variant_id'] ?? '?', 'reason' => 'المتغير مش موجود أو غامض — حدده بـ sku.'];
                continue;
            }
            $minQty = isset($row['min_qty']) && is_numeric($row['min_qty']) && (float) $row['min_qty'] > 0 ? (float) $row['min_qty'] : 1.0;
            if (! isset($row['price']) || ! is_numeric($row['price']) || (float) $row['price'] <= 0) {
                $blocked[] = ['sku' => $variant->sku, 'reason' => 'السعر لازم يكون رقم أكبر من صفر.'];
                continue;
            }
            $price = (float) $row['price'];
            if ($isWholesaleList && $variant->retail_price !== null && $price >= (float) $variant->retail_price) {
                $blocked[] = ['sku' => $variant->sku, 'reason' => 'سعر الجملة هيبقى أكبر من أو يساوي القطاعي.'];
                continue;
            }
            $cost = $variant->cost_price !== null ? (float) $variant->cost_price : null;
            if ($cost !== null && $cost > 0 && $price < $cost) {
                $message = $canViewCost ? "السعر ({$price}) أقل من التكلفة ({$cost})." : 'السعر أقل من التكلفة (محمي).';
                if ($belowCostPolicy === 'block') {
                    $blocked[] = ['sku' => $variant->sku, 'reason' => $message];
                    continue;
                }
                $warnings[] = "{$variant->sku}: {$message}";
            }
            $current = $sourceItems->get($variant->id.':'.number_format($minQty, 4, '.', ''));
            $rows[] = [
                'product_variant_id' => $variant->id,
                'min_qty' => $minQty,
                'price' => $price,
                'old_price' => $current ? (float) $current->price : null,
                'sku' => $variant->sku,
            ];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'كل البنود تم استبعادها بواسطة الحراس.']);
        }

        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            $name = $source->name.' (من '.$startsAt->format('Y-m-d').')';
        }
        $header = [
            'name' => $name,
            'type' => $sourceType,
            'customer_id' => $source->customer_id,
            'customer_group_id' => $source->customer_group_id,
            'branch_id' => $source->branch_id,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $source->ends_at?->format('Y-m-d H:i:s'),
            'priority' => isset($args['priority']) && is_numeric($args['priority']) ? (int) $args['priority'] : ((int) $source->priority + 1),
            'is_active' => true,
        ];
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, []);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        if ($scope['branchId'] !== null) {
            $header['branch_id'] = $scope['branchId'];
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['source_price_list_id' => $source->id, 'header' => $header, 'rows' => $rows],
            risk: $this->risk(),
            title: 'جدولة تغيير أسعار من: '.$source->name,
            before: ['source' => $source->name],
            after: ['name' => $name, 'starts_at' => $header['starts_at'], 'items_count' => count($rows), 'blocked_count' => count($blocked)],
            warnings: $warnings,
            typedConfirmation: $name,
            typedConfirmationHint: 'اكتب اسم القائمة المجدولة',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => ['scheduled_list_name' => $name, 'starts_at' => $header['starts_at'], 'items_count' => count($rows)],
                'rows' => array_map(fn ($r) => ['sku' => $r['sku'], 'min_qty' => $r['min_qty'], 'old_price' => $r['old_price'], 'new_price' => $r['price'], 'delta' => $r['old_price'] !== null ? round($r['price'] - $r['old_price'], 2) : $r['price'], 'delta_pct' => null], $rows),
                'blocked' => $blocked,
            ],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $header = (array) $action->args['header'];
        $rows = (array) $action->args['rows'];

        $newList = null;
        app(CatalogRetailPriceObserver::class)->batch(function () use ($actor, $header, $rows, &$newList): void {
            $newList = $this->lists->create($header, $actor);
            foreach ($rows as $row) {
                $this->lists->createItem($newList->fresh(), [
                    'product_variant_id' => (int) $row['product_variant_id'],
                    'min_qty' => (float) $row['min_qty'],
                    'price' => (float) $row['price'],
                ], $actor);
            }
        });

        StaffAdministration::audit(
            $actor,
            'price_list.scheduled',
            'price_list',
            $newList->id,
            ['name', 'type'],
            [],
            ['name' => $newList->name, 'type' => $newList->type instanceof PriceListType ? $newList->type->value : (string) $newList->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$newList->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم جدولة تغيير الأسعار من '.$header['starts_at'].'.', [
            'price_list_id' => $newList->id,
            'items_count' => count($rows),
            'deep_link' => '#/pricing?list='.$newList->id,
        ]);
    }
}
