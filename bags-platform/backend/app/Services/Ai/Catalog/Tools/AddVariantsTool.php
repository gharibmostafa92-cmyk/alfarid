<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\Variant\VariantRules;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\VariantService;
use App\Services\CostAccess;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddVariantsTool implements LocksTargets, WriteTool
{
    public function __construct(
        private VariantService $variants,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'add_variants';
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
        return ['products.update'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إضافة متغيرات جديدة لمنتج موجود عن طريق variant_matrix (colors × sizes × capacities). التركيبات الموجودة فعلًا بتتجاهل أوتوماتيك.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id', 'variant_matrix'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'variant_matrix' => [
                        'type' => 'OBJECT',
                        'properties' => (object) [
                            'colors' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'sizes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'capacities' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        ],
                    ],
                    'default_prices' => [
                        'type' => 'OBJECT',
                        'properties' => (object) [
                            'retail_price' => ['type' => 'NUMBER'],
                            'wholesale_price' => ['type' => 'NUMBER'],
                            'cost_price' => ['type' => 'NUMBER'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::with('variants')->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $canViewCost = CostAccess::canView($actor);
        $defaultPrices = is_array($args['default_prices'] ?? null) ? $args['default_prices'] : [];
        if (! $canViewCost) {
            unset($defaultPrices['cost_price'], $args['cost_price']);
        }

        $activeSample = $product->variants->where('is_active', true)->first() ?? $product->variants->first();
        $retailPrice = array_key_exists('retail_price', $defaultPrices) && $defaultPrices['retail_price'] !== null
            ? (float) $defaultPrices['retail_price']
            : ($activeSample?->retail_price !== null ? (float) $activeSample->retail_price : null);
        $wholesalePrice = array_key_exists('wholesale_price', $defaultPrices) && $defaultPrices['wholesale_price'] !== null
            ? (float) $defaultPrices['wholesale_price']
            : ($activeSample?->wholesale_price !== null ? (float) $activeSample->wholesale_price : null);
        $costPrice = $canViewCost && array_key_exists('cost_price', $defaultPrices) && $defaultPrices['cost_price'] !== null
            ? (float) $defaultPrices['cost_price']
            : null;

        $matrix = is_array($args['variant_matrix'] ?? null) ? $args['variant_matrix'] : [];
        $rawColors = array_values(array_filter(array_map('trim', (array) ($matrix['colors'] ?? [])), fn ($v) => $v !== ''));
        $rawSizes = array_values(array_filter(array_map('trim', (array) ($matrix['sizes'] ?? [])), fn ($v) => $v !== ''));
        $rawCapacities = array_values(array_filter(array_map('trim', (array) ($matrix['capacities'] ?? [])), fn ($v) => $v !== ''));

        $resolvedColors = [];
        foreach ($rawColors as $colorInput) {
            $resolvedColors[] = CatalogArgs::resolveColor((string) $colorInput);
        }

        $colorAxis = $resolvedColors !== [] ? $resolvedColors : [null];
        $sizeAxis = $rawSizes !== [] ? $rawSizes : [null];
        $capacityAxis = $rawCapacities !== [] ? $rawCapacities : [null];

        $totalCombinations = count($colorAxis) * count($sizeAxis) * count($capacityAxis);
        if ($totalCombinations > 100) {
            throw ValidationException::withMessages([
                'variant_matrix' => ["عدد المتغيرات الناتج ({$totalCombinations}) أكبر من الحد المسموح (100 متغير)."],
            ]);
        }

        $existingCombos = [];
        foreach ($product->variants as $v) {
            $key = mb_strtolower((string) ($v->color_name_en ?? '')).'|'.mb_strtolower((string) ($v->size ?? '')).'|'.mb_strtolower((string) ($v->capacity ?? ''));
            $existingCombos[$key] = true;
        }

        $newVariants = [];
        $matrixRows = [];
        $baseSort = (int) $product->variants->max('sort_order') + 1;

        foreach ($colorAxis as $colorItem) {
            foreach ($sizeAxis as $sizeItem) {
                foreach ($capacityAxis as $capItem) {
                    $colorEn = $colorItem['color_name_en'] ?? null;
                    $comboKey = mb_strtolower((string) ($colorEn ?? '')).'|'.mb_strtolower((string) ($sizeItem ?? '')).'|'.mb_strtolower((string) ($capItem ?? ''));
                    $isSkipped = isset($existingCombos[$comboKey]);

                    $uiRow = [
                        'color_hex' => $colorItem['color_hex'] ?? null,
                        'color' => $colorItem['color_name_ar'] ?? null,
                        'color_en' => $colorEn,
                        'size' => $sizeItem,
                        'capacity' => $capItem,
                        'retail' => $retailPrice,
                        'wholesale' => $wholesalePrice,
                        'status' => $isSkipped ? 'skipped' : 'new',
                    ];
                    if ($canViewCost && $costPrice !== null) {
                        $uiRow['cost_price'] = $costPrice;
                    }
                    $matrixRows[] = $uiRow;

                    if (! $isSkipped) {
                        $existingCombos[$comboKey] = true;
                        $vData = [
                            'color_name_ar' => $colorItem['color_name_ar'] ?? null,
                            'color_name_en' => $colorEn,
                            'color_hex' => $colorItem['color_hex'] ?? null,
                            'size' => $sizeItem,
                            'capacity' => $capItem,
                            'retail_price' => $retailPrice,
                            'wholesale_price' => $wholesalePrice,
                            'is_active' => true,
                            'sort_order' => $baseSort++,
                        ];
                        if ($canViewCost && $costPrice !== null) {
                            $vData['cost_price'] = $costPrice;
                        }
                        $newVariants[] = $vData;
                    }
                }
            }
        }

        if ($newVariants === []) {
            throw ValidationException::withMessages([
                'variant_matrix' => ['كل التركيبات المطلوبة موجودة بالفعل في المنتج.'],
            ]);
        }

        $rules = ['variants' => ['required', 'array', 'min:1', 'max:100']];
        foreach (VariantRules::base(null, 'variants.*.') as $field => $rule) {
            $rules["variants.*.$field"] = $rule;
        }
        CatalogArgs::validate(['variants' => $newVariants], $rules);

        $skippedCount = count($matrixRows) - count($newVariants);
        $warnings = [
            'تنفيذ المتغيرات بيتم كحزمة واحدة (transaction واحدة): لو متغير واحد فشل، الإضافة كلها بتتلغي.',
        ];
        if ($skippedCount > 0) {
            $warnings[] = "تم تجاهل {$skippedCount} متغير لأنهم موجودين بالفعل.";
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'product_id' => $product->id,
                'variants' => $newVariants,
            ],
            risk: $this->risk(),
            title: 'إضافة '.count($newVariants)." متغير للمنتج {$product->code}",
            before: ['variants_count' => $product->variants->count()],
            after: [
                'added_count' => count($newVariants),
                'skipped_count' => $skippedCount,
            ],
            warnings: $warnings,
            expectedVersions: ['product:'.$product->id => (int) $product->lock_version],
            extraPreview: [
                'block_type' => 'variant_matrix',
                'rows' => $matrixRows,
            ],
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $expected = $action->expected_versions['product:'.$product->id] ?? null;
        if ($expected !== null && (int) $product->lock_version !== (int) $expected) {
            throw new BusinessException('المنتج تعدّل في جلسة أخرى؛ أعد تحميل الصفحة وحاول مجددًا.', [], 409);
        }

        $variantsToCreate = (array) ($action->args['variants'] ?? []);
        $canViewCost = CostAccess::canView($actor);

        $createdIds = DB::transaction(function () use ($product, $variantsToCreate, $canViewCost, $expected): array {
            $ids = [];
            foreach ($variantsToCreate as $vData) {
                if (! $canViewCost) {
                    unset($vData['cost_price']);
                }
                $created = $this->variants->create($product, $vData, $expected === null ? null : (int) $expected);
                $ids[] = $created->id;
            }

            return $ids;
        });

        StaffAdministration::audit(
            $actor,
            'variant.bulk_created',
            'product',
            $product->id,
            ['variants'],
            [],
            ['count' => count($createdIds)],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'اتضاف '.count($createdIds)." متغير للمنتج {$product->code}.", [
            'product_id' => $product->id,
            'code' => $product->code,
            'added_count' => count($createdIds),
            'variant_ids' => $createdIds,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }
}
