<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\PriceListType;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceListItem;
use App\Models\User;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\PriceListService;
use App\Services\PricingService;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final class ClonePriceListTool implements WriteTool
{
    public function __construct(
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'clone_price_list';
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
            'description' => 'نسخ قائمة أسعار لقائمة جديدة مع تعديل نسبة (مثال: "اعمل قائمة موزعين من الجملة بخصم 7% وقرّب لأقرب 5"). دي قوائم أسعار — مش السعر الأساسي (ده bulk_update_prices).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['source_price_list_id', 'name'],
                'properties' => (object) [
                    'source_price_list_id' => ['type' => 'INTEGER'],
                    'name' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => array_column(PriceListType::cases(), 'value')],
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_group_id' => ['type' => 'INTEGER'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'percent' => ['type' => 'NUMBER'],
                    'rounding' => ['type' => 'STRING', 'enum' => ['none', 'nearest_5', 'nearest_10', 'end_with_9', 'end_with_99']],
                    'priority' => ['type' => 'INTEGER'],
                    'starts_at' => ['type' => 'STRING'],
                    'ends_at' => ['type' => 'STRING'],
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
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'اسم القائمة الجديدة مطلوب.']);
        }
        try {
            $source = $this->lists->find((int) $args['source_price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار المصدر غير موجودة.', auditable: false);
        }

        $sourceType = $source->type instanceof PriceListType ? $source->type->value : (string) $source->type;
        $type = (string) ($args['type'] ?? $sourceType);
        if (! in_array($type, array_column(PriceListType::cases(), 'value'), true)) {
            throw ValidationException::withMessages(['type' => 'نوع القائمة غير صالح.']);
        }
        $header = ['name' => $name, 'type' => $type];
        if ($type === PriceListType::CustomerSpecific->value) {
            $customerId = $args['customer_id'] ?? $source->customer_id;
            if (! $customerId || ! Customer::query()->find((int) $customerId)) {
                throw ValidationException::withMessages(['customer_id' => 'قائمة العميل الخاص تتطلب عميل موجود.']);
            }
            $header['customer_id'] = (int) $customerId;
        } elseif ($type === PriceListType::CustomerGroup->value) {
            $groupId = $args['customer_group_id'] ?? $source->customer_group_id;
            if (! $groupId || ! CustomerGroup::query()->find((int) $groupId)) {
                throw ValidationException::withMessages(['customer_group_id' => 'قائمة المجموعة تتطلب مجموعة موجودة.']);
            }
            $header['customer_group_id'] = (int) $groupId;
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $header['branch_id'] = $scope['branchId'] ?? $source->branch_id;
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($args[$field]) && trim((string) $args[$field]) !== '') {
                $header[$field] = trim((string) $args[$field]);
            }
        }
        if (isset($args['priority']) && is_numeric($args['priority'])) {
            $header['priority'] = (int) $args['priority'];
        }
        try {
            if (isset($header['starts_at'], $header['ends_at']) && Carbon::parse($header['ends_at'])->lt(Carbon::parse($header['starts_at']))) {
                throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }

        $percent = isset($args['percent']) && is_numeric($args['percent']) ? (float) $args['percent'] : 0.0;
        $rounding = (string) ($args['rounding'] ?? 'none');
        $maxItems = max(1, (int) $this->settings->get('ai_pricing_max_items_per_action', 200));

        $sourceItems = PriceListItem::query()->where('price_list_id', $source->id)->with('variant:id,sku,retail_price,cost_price')->orderBy('id')->get();
        if ($sourceItems->count() > $maxItems) {
            throw ValidationException::withMessages(['source_price_list_id' => "بنود المصدر ({$sourceItems->count()}) أكتر من الحد ({$maxItems})."]);
        }

        $canViewCost = CostAccess::canView($actor);
        $belowCostPolicy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        $belowCostPolicy = in_array($belowCostPolicy, ['block', 'warn'], true) ? $belowCostPolicy : 'block';
        $isWholesaleList = in_array($type, [PriceListType::Wholesale->value, PriceListType::Distributor->value], true);

        $rows = [];
        $blocked = [];
        $warnings = [];
        $uiRows = [];
        foreach ($sourceItems as $item) {
            $newPrice = PricingService::applyRounding((float) $item->price * (1.0 + $percent / 100.0), $rounding);
            if ($newPrice <= 0) {
                $blocked[] = ['sku' => $item->variant?->sku ?? ('#'.$item->product_variant_id), 'reason' => 'السعر بعد التعديل صفر أو سالب.'];

                continue;
            }
            if ($isWholesaleList && $item->variant?->retail_price !== null && $newPrice >= (float) $item->variant->retail_price) {
                $blocked[] = ['sku' => $item->variant?->sku ?? ('#'.$item->product_variant_id), 'reason' => 'سعر الجملة هيبقى أكبر من أو يساوي القطاعي.'];

                continue;
            }
            $cost = $item->variant?->cost_price !== null ? (float) $item->variant->cost_price : null;
            if ($cost !== null && $cost > 0 && $newPrice < $cost) {
                $message = $canViewCost ? "السعر ({$newPrice}) أقل من التكلفة ({$cost})." : 'السعر أقل من التكلفة (محمي).';
                if ($belowCostPolicy === 'block') {
                    $blocked[] = ['sku' => $item->variant?->sku ?? ('#'.$item->product_variant_id), 'reason' => $message];

                    continue;
                }
                $warnings[] = ($item->variant?->sku ?? '#'.$item->product_variant_id).': '.$message;
            }
            $rows[] = [
                'product_variant_id' => (int) $item->product_variant_id,
                'min_qty' => (float) $item->min_qty,
                'price' => $newPrice,
                'starts_at' => $item->starts_at?->format('Y-m-d H:i:s'),
                'ends_at' => $item->ends_at?->format('Y-m-d H:i:s'),
            ];
            $uiRows[] = [
                'sku' => $item->variant?->sku ?? ('#'.$item->product_variant_id),
                'min_qty' => (float) $item->min_qty,
                'old_price' => (float) $item->price,
                'new_price' => $newPrice,
                'delta' => round($newPrice - (float) $item->price, 2),
                'delta_pct' => $percent,
            ];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['source_price_list_id' => 'كل بنود المصدر تم استبعادها بواسطة الحراس.']);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['source_price_list_id' => $source->id, 'header' => $header, 'rows' => $rows, 'percent' => $percent, 'rounding' => $rounding],
            risk: $this->risk(),
            title: 'نسخ قائمة الأسعار: '.$source->name.' ← '.$name,
            before: ['source_items_count' => $sourceItems->count()],
            after: ['name' => $name, 'items_count' => count($rows), 'blocked_count' => count($blocked), 'percent' => $percent],
            warnings: $warnings,
            typedConfirmation: $name,
            typedConfirmationHint: 'اكتب اسم القائمة الجديدة',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => ['new_list_name' => $name, 'items_count' => count($rows), 'blocked_count' => count($blocked)],
                'rows' => $uiRows,
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
                    'starts_at' => $row['starts_at'] ?? null,
                    'ends_at' => $row['ends_at'] ?? null,
                ], $actor);
            }
        });

        StaffAdministration::audit(
            $actor,
            'price_list.cloned',
            'price_list',
            $newList->id,
            ['name', 'type'],
            [],
            ['name' => $newList->name, 'type' => $newList->type instanceof PriceListType ? $newList->type->value : (string) $newList->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$newList->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم نسخ القائمة إلى '.$newList->name.'.', [
            'price_list_id' => $newList->id,
            'items_count' => count($rows),
            'deep_link' => '#/pricing?list='.$newList->id,
        ]);
    }
}
