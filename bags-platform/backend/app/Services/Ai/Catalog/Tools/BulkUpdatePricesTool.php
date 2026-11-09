<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\User;
use App\Observers\CatalogRetailPriceObserver;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\CatalogUndoGuard;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\VariantService;
use App\Services\Shared\CostAccess;
use App\Services\Pricing\PricingService;
use App\Services\Shared\SettingService;
use App\Services\Auth\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class BulkUpdatePricesTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private VariantService $variants,
        private SettingService $settings,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'bulk_update_prices';
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
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تعديل جماعي لأسعار المتغيرات النشطة (نسبة مئوية / فرق ثابت / قيمة ثابتة) مع التقريب وحراس التسعير (عدم البيع بأقل من التكلفة وبقاء الجملة أقل من القطاعي).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['scope', 'field', 'operation', 'value'],
                'properties' => (object) [
                    'scope' => [
                        'type' => 'OBJECT',
                        'properties' => (object) [
                            'category_id' => ['type' => 'INTEGER'],
                            'include_children' => ['type' => 'BOOLEAN'],
                            'product_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                            'status' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => array_column(ProductStatus::cases(), 'value')]],
                            'sales_channel' => ['type' => 'STRING', 'enum' => array_column(SalesChannel::cases(), 'value')],
                        ],
                    ],
                    'field' => ['type' => 'STRING', 'enum' => ['retail_price', 'wholesale_price']],
                    'operation' => ['type' => 'STRING', 'enum' => ['percent', 'fixed_delta', 'set']],
                    'value' => ['type' => 'NUMBER'],
                    'rounding' => ['type' => 'STRING', 'enum' => ['none', 'nearest_5', 'nearest_10', 'end_with_9', 'end_with_99']],
                    'guard' => [
                        'type' => 'OBJECT',
                        'properties' => (object) [
                            'never_below_cost' => ['type' => 'BOOLEAN'],
                            'keep_wholesale_below_retail' => ['type' => 'BOOLEAN'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $field = (string) ($args['field'] ?? 'retail_price');
        if (! in_array($field, ['retail_price', 'wholesale_price'], true)) {
            throw ValidationException::withMessages(['field' => ['الحقل لازم يكون retail_price أو wholesale_price.']]);
        }

        $operation = (string) ($args['operation'] ?? 'percent');
        if (! in_array($operation, ['percent', 'fixed_delta', 'set'], true)) {
            throw ValidationException::withMessages(['operation' => ['العملية لازم تكون percent أو fixed_delta أو set.']]);
        }

        if (! isset($args['value']) || ! is_numeric($args['value'])) {
            throw ValidationException::withMessages(['value' => ['قيمة التعديل مطلوبة.']]);
        }
        $value = (float) $args['value'];

        $rounding = (string) ($args['rounding'] ?? 'none');
        if (! in_array($rounding, ['none', 'nearest_5', 'nearest_10', 'end_with_9', 'end_with_99'], true)) {
            $rounding = 'none';
        }

        $guardCfg = is_array($args['guard'] ?? null) ? $args['guard'] : [];
        $neverBelowCost = ! array_key_exists('never_below_cost', $guardCfg) || (bool) $guardCfg['never_below_cost'];
        $keepWholesaleBelowRetail = ! array_key_exists('keep_wholesale_below_retail', $guardCfg) || (bool) $guardCfg['keep_wholesale_below_retail'];

        $scope = is_array($args['scope'] ?? null) ? $args['scope'] : [];
        $hasScopeFilter = ! empty($scope['category_id']) || ! empty($scope['product_ids']) || ! empty($scope['status']) || ! empty($scope['sales_channel']);
        if (! $hasScopeFilter) {
            throw ValidationException::withMessages(['scope' => ['لازم تحدد نطاق (قسم أو منتجات أو حالة) لتعديل الأسعار الجماعي.']]);
        }

        $query = ProductVariant::query()
            ->with('product:id,code,name_ar,category_id,status,sales_channel')
            ->where('product_variants.is_active', true)
            ->whereHas('product', function ($q) use ($scope): void {
                if (! empty($scope['category_id'])) {
                    $cat = Category::find((int) $scope['category_id']);
                    if ($cat) {
                        $includeChildren = ! array_key_exists('include_children', $scope) || (bool) $scope['include_children'];
                        $ids = $includeChildren ? [$cat->id, ...$cat->descendantIds()] : [$cat->id];
                        $q->whereIn('category_id', $ids);
                    } else {
                        $q->where('category_id', (int) $scope['category_id']);
                    }
                }
                if (! empty($scope['product_ids']) && is_array($scope['product_ids'])) {
                    $q->whereIn('id', array_map('intval', $scope['product_ids']));
                }
                if (! empty($scope['status']) && is_array($scope['status'])) {
                    $q->whereIn('status', $scope['status']);
                }
                if (! empty($scope['sales_channel'])) {
                    $q->where('sales_channel', (string) $scope['sales_channel']);
                }
            })
            ->orderBy('product_variants.id');

        $maxItems = max(1, (int) $this->settings->get('ai_catalog_bulk_price_max_items', 200));
        $totalMatching = (clone $query)->count();
        if ($totalMatching === 0) {
            throw ValidationException::withMessages(['scope' => ['مفيش متغيرات نشطة مطابقة للنطاق المحدد.']]);
        }
        if ($totalMatching > $maxItems) {
            throw ValidationException::withMessages([
                'scope' => ["عدد المتغيرات المطابقة ({$totalMatching}) أكبر من الحد المسموح ({$maxItems}). ضيّق النطاق."],
            ]);
        }

        $variants = $query->get();
        $canViewCost = CostAccess::canView($actor);

        $rowsToApply = [];
        $uiAllRows = [];
        $blockedRows = [];
        $blockedByCostCount = 0;
        $expectedVersions = [];
        $inverse = [];
        $diffs = [];

        foreach ($variants as $v) {
            $oldPrice = $v->getAttribute($field) !== null ? (float) $v->getAttribute($field) : null;
            if ($oldPrice === null && $operation !== 'set') {
                $blockedRows[] = [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'product_code' => $v->product?->code,
                    'reason' => 'السعر الحالي فاضي؛ استخدم عملية set لتحديد سعر مبدئي.',
                ];

                continue;
            }

            $basePrice = $oldPrice ?? 0.0;
            $rawNew = match ($operation) {
                'percent' => $basePrice * (1.0 + ($value / 100.0)),
                'fixed_delta' => $basePrice + $value,
                'set' => $value,
            };

            $newPrice = self::applyRounding($rawNew, $rounding);

            if ($newPrice <= 0) {
                $blockedRows[] = [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'product_code' => $v->product?->code,
                    'reason' => 'السعر الجديد لازم يكون أكبر من صفر.',
                ];

                continue;
            }

            // Guard 1: never_below_cost
            if ($neverBelowCost && $v->cost_price !== null && (float) $v->cost_price > 0 && $newPrice < (float) $v->cost_price) {
                $blockedByCostCount++;
                $blockedRows[] = [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'product_code' => $v->product?->code,
                    'reason' => $canViewCost
                        ? 'السعر الجديد ('.$newPrice.') أقل من سعر التكلفة ('.(float) $v->cost_price.').'
                        : 'أسباب تسعير محمية',
                ];

                continue;
            }

            // Guard 2: keep_wholesale_below_retail
            if ($keepWholesaleBelowRetail) {
                $effectiveRetail = $field === 'retail_price' ? $newPrice : ($v->retail_price !== null ? (float) $v->retail_price : null);
                $effectiveWholesale = $field === 'wholesale_price' ? $newPrice : ($v->wholesale_price !== null ? (float) $v->wholesale_price : null);
                if ($effectiveRetail !== null && $effectiveWholesale !== null && $effectiveWholesale >= $effectiveRetail) {
                    $blockedRows[] = [
                        'variant_id' => $v->id,
                        'sku' => $v->sku,
                        'product_code' => $v->product?->code,
                        'reason' => "سعر الجملة ({$effectiveWholesale}) هيبقى أكبر من أو يساوي القطاعي ({$effectiveRetail}).",
                    ];

                    continue;
                }
            }

            $delta = $oldPrice !== null ? round($newPrice - $oldPrice, 2) : $newPrice;
            $pct = ($oldPrice !== null && $oldPrice > 0) ? round((($newPrice - $oldPrice) / $oldPrice) * 100, 2) : null;

            $rowsToApply[] = [
                'id' => $v->id,
                'old' => $oldPrice,
                'new' => $newPrice,
                'v' => (int) $v->lock_version,
            ];
            $expectedVersions['variant:'.$v->id] = (int) $v->lock_version;
            $inverse[(string) $v->id] = $oldPrice;
            $diffs[] = $delta;

            $uiAllRows[] = [
                'variant_id' => $v->id,
                'sku' => $v->sku,
                'product_code' => $v->product?->code,
                'product_name' => $v->product?->name_ar,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'delta' => $delta,
                'delta_pct' => $pct,
            ];
        }

        if ($rowsToApply === []) {
            $reasonMsg = $blockedByCostCount > 0 && ! $canViewCost
                ? "{$blockedByCostCount} متغيرات اتستبعدت لأسباب تسعير محمية."
                : 'كل المتغيرات المطابقة تم استبعادها بواسطة حراس التسعير.';
            throw ValidationException::withMessages(['value' => [$reasonMsg]]);
        }

        $topChanges = $uiAllRows;
        usort($topChanges, fn ($a, $b) => abs((float) $b['delta']) <=> abs((float) $a['delta']));
        $topChanges = array_slice($topChanges, 0, 10);

        $totalDelta = round(array_sum($diffs), 2);
        $avgDelta = count($diffs) > 0 ? round($totalDelta / count($diffs), 2) : 0.0;

        $summary = [
            'field' => $field,
            'operation' => $operation,
            'value' => $value,
            'rounding' => $rounding,
            'total_matched' => $totalMatching,
            'updated_count' => count($rowsToApply),
            'blocked_count' => count($blockedRows),
            'total_delta' => $totalDelta,
            'average_delta' => $avgDelta,
        ];

        $warnings = [];
        if ($blockedByCostCount > 0 && ! $canViewCost) {
            $warnings[] = "{$blockedByCostCount} متغيرات اتستبعدت لأسباب تسعير محمية";
        } elseif (count($blockedRows) > 0) {
            $warnings[] = 'تم استبعاد '.count($blockedRows).' متغير بسبب حراس التسعير.';
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'field' => $field,
                'operation' => $operation,
                'value' => $value,
                'rounding' => $rounding,
                'rows' => $rowsToApply,
            ],
            risk: $this->risk(),
            title: 'تعديل أسعار '.count($rowsToApply).' متغير ('.($field === 'retail_price' ? 'قطاعي' : 'جملة').')',
            before: ['count' => count($rowsToApply)],
            after: $summary,
            warnings: $warnings,
            expectedVersions: $expectedVersions,
            inverse: [
                'field' => $field,
                'prices' => $inverse,
            ],
            typedConfirmation: 'تنفيذ',
            typedConfirmationHint: 'اكتب كلمة تنفيذ للتأكيد',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => $summary,
                'top' => $topChanges,
                'blocked' => $blockedRows,
                'rows' => $uiAllRows,
            ],
        );
    }

    public static function applyRounding(float $price, string $rounding): float
    {
        return PricingService::applyRounding($price, $rounding);
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $field = (string) $action->args['field'];
        $rows = (array) ($action->args['rows'] ?? []);

        try {
            app(CatalogRetailPriceObserver::class)->batch(function () use ($rows, $field): void {
                foreach (collect($rows)->sortBy('id') as $row) {
                    $variant = ProductVariant::findOrFail((int) $row['id']);
                    $this->variants->update($variant, [
                        $field => (float) $row['new'],
                        'expected_version' => (int) $row['v'],
                    ]);
                }
            });
        } catch (BusinessException $e) {
            if ($e->status === 409) {
                throw new BusinessException('حد عدّل أسعار أثناء المراجعة؛ اطلب الاقتراح من جديد', [], 409);
            }
            throw $e;
        }

        StaffAdministration::audit(
            $actor,
            'variant.bulk_prices_updated',
            'variant',
            null,
            [$field],
            ['updated_count' => count($rows)],
            ['updated_count' => count($rows)],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'variant:bulk_prices', 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'اتعدلت أسعار '.count($rows).' متغير بنجاح.', [
            'updated_count' => count($rows),
            'field' => $field,
            'deep_link' => '#/products',
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse)
            && ! empty($action->inverse['field'])
            && ! empty($action->inverse['prices']);
    }

    public function targetState(AiPendingAction $action): string
    {
        $field = (string) ($action->args['field'] ?? 'retail_price');
        $rows = collect((array) ($action->args['rows'] ?? []))->sortBy('id');
        $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $variants = ProductVariant::whereIn('id', $ids)->orderBy('id')->get();

        $state = [];
        foreach ($variants as $v) {
            $state[] = [
                (int) $v->id,
                $v->getAttribute($field) !== null ? round((float) $v->getAttribute($field), 2) : null,
                (int) $v->lock_version,
            ];
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $field = (string) $action->inverse['field'];
        $prices = (array) $action->inverse['prices'];
        $variants = ProductVariant::whereIn('id', array_keys($prices))->get()->keyBy('id');
        CatalogUndoGuard::assertUnchanged($this, $action);

        app(CatalogRetailPriceObserver::class)->batch(function () use ($prices, $field, $variants): void {
            ksort($prices, SORT_NUMERIC);
            foreach ($prices as $variantId => $oldPrice) {
                $variant = $variants->get((int) $variantId);
                $this->variants->update($variant, [
                    $field => $oldPrice !== null ? (float) $oldPrice : null,
                    'expected_version' => (int) $variant->lock_version,
                ]);
            }
        });

        return ToolOutcome::result(true, 'تم التراجع عن تعديل الأسعار الجماعي لـ '.count($prices).' متغير.', [
            'restored_count' => count($prices),
            'deep_link' => '#/products',
        ]);
    }
}
