<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Occasion;
use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Variant\VariantRules;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\ProductService;
use App\Services\Shared\CostAccess;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateProductDraftTool implements LocksTargets, WriteTool
{
    public function __construct(
        private ProductService $products,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'create_product_draft';
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
        return ['products.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء منتج جديد كمسودة دايمًا مع متغيراته من حاصل الضرب الديكارتي لـ variant_matrix (colors × sizes × capacities).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['category_id', 'name_ar', 'name_en', 'sales_channel'],
                'properties' => (object) [
                    'category_id' => ['type' => 'INTEGER'],
                    'name_ar' => ['type' => 'STRING'],
                    'name_en' => ['type' => 'STRING'],
                    'slug' => ['type' => 'STRING'],
                    'sales_channel' => ['type' => 'STRING', 'enum' => array_column(SalesChannel::cases(), 'value')],
                    'description_ar' => ['type' => 'STRING'],
                    'description_en' => ['type' => 'STRING'],
                    'material_ar' => ['type' => 'STRING'],
                    'material_en' => ['type' => 'STRING'],
                    'dimensions' => [
                        'type' => 'OBJECT',
                        'properties' => (object) [
                            'length_cm' => ['type' => 'NUMBER'],
                            'width_cm' => ['type' => 'NUMBER'],
                            'height_cm' => ['type' => 'NUMBER'],
                            'weight_kg' => ['type' => 'NUMBER'],
                        ],
                    ],
                    'occasions' => [
                        'type' => 'ARRAY',
                        'items' => ['type' => 'STRING', 'enum' => array_column(Occasion::cases(), 'value')],
                    ],
                    'badge_ar' => ['type' => 'STRING'],
                    'badge_en' => ['type' => 'STRING'],
                    'is_featured' => ['type' => 'BOOLEAN'],
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
        $canViewCost = CostAccess::canView($actor);

        $dims = is_array($args['dimensions'] ?? null) ? $args['dimensions'] : [];
        $defaultPrices = is_array($args['default_prices'] ?? null) ? $args['default_prices'] : [];
        if (! $canViewCost) {
            unset($defaultPrices['cost_price'], $args['cost_price']);
        }

        $retailPrice = isset($defaultPrices['retail_price']) && $defaultPrices['retail_price'] !== null
            ? (float) $defaultPrices['retail_price']
            : (isset($args['retail_price']) ? (float) $args['retail_price'] : null);
        $wholesalePrice = isset($defaultPrices['wholesale_price']) && $defaultPrices['wholesale_price'] !== null
            ? (float) $defaultPrices['wholesale_price']
            : (isset($args['wholesale_price']) ? (float) $args['wholesale_price'] : null);
        $costPrice = $canViewCost && isset($defaultPrices['cost_price']) && $defaultPrices['cost_price'] !== null
            ? (float) $defaultPrices['cost_price']
            : null;

        // Build Cartesian product from variant_matrix
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

        $variants = [];
        $matrixRows = [];
        $sortOrder = 0;

        foreach ($colorAxis as $colorItem) {
            foreach ($sizeAxis as $sizeItem) {
                foreach ($capacityAxis as $capItem) {
                    $variantRow = [
                        'color_name_ar' => $colorItem['color_name_ar'] ?? null,
                        'color_name_en' => $colorItem['color_name_en'] ?? null,
                        'color_hex' => $colorItem['color_hex'] ?? null,
                        'size' => $sizeItem,
                        'capacity' => $capItem,
                        'retail_price' => $retailPrice,
                        'wholesale_price' => $wholesalePrice,
                        'is_active' => true,
                        'sort_order' => $sortOrder++,
                    ];
                    if ($canViewCost && $costPrice !== null) {
                        $variantRow['cost_price'] = $costPrice;
                    }
                    $variants[] = $variantRow;

                    $uiRow = [
                        'color_hex' => $colorItem['color_hex'] ?? null,
                        'color' => $colorItem['color_name_ar'] ?? null,
                        'color_en' => $colorItem['color_name_en'] ?? null,
                        'size' => $sizeItem,
                        'capacity' => $capItem,
                        'retail' => $retailPrice,
                        'wholesale' => $wholesalePrice,
                        'status' => 'new',
                    ];
                    if ($canViewCost && $costPrice !== null) {
                        $uiRow['cost_price'] = $costPrice;
                    }
                    $matrixRows[] = $uiRow;
                }
            }
        }

        $payload = [
            'category_id' => isset($args['category_id']) ? (int) $args['category_id'] : null,
            'name_ar' => $args['name_ar'] ?? null,
            'name_en' => $args['name_en'] ?? null,
            'slug' => $args['slug'] ?? null,
            'sales_channel' => $args['sales_channel'] ?? SalesChannel::Both->value,
            'description_ar' => $args['description_ar'] ?? null,
            'description_en' => $args['description_en'] ?? null,
            'material_ar' => $args['material_ar'] ?? null,
            'material_en' => $args['material_en'] ?? null,
            'length_cm' => $dims['length_cm'] ?? ($args['length_cm'] ?? null),
            'width_cm' => $dims['width_cm'] ?? ($args['width_cm'] ?? null),
            'height_cm' => $dims['height_cm'] ?? ($args['height_cm'] ?? null),
            'weight_kg' => $dims['weight_kg'] ?? ($args['weight_kg'] ?? null),
            'occasions' => $args['occasions'] ?? null,
            'badge_ar' => $args['badge_ar'] ?? null,
            'badge_en' => $args['badge_en'] ?? null,
            'is_featured' => isset($args['is_featured']) ? (bool) $args['is_featured'] : false,
            'status' => 'draft',
            'variants' => $variants,
        ];

        $rules = StoreProductRequest::baseRules();
        $rules['variants'] = ['required', 'array', 'min:1', 'max:100'];
        foreach (VariantRules::base(null, 'variants.*.') as $field => $rule) {
            if (in_array($field, ['sku', 'barcode'], true)) {
                $rule[] = 'distinct';
            }
            $rules["variants.*.$field"] = $rule;
        }

        $validated = CatalogArgs::validate($payload, $rules);
        $validated['status'] = 'draft';
        $validated['create_key'] = (string) ($args['create_key'] ?? Str::uuid());

        if (! $canViewCost && isset($validated['variants']) && is_array($validated['variants'])) {
            foreach ($validated['variants'] as $idx => $v) {
                unset($validated['variants'][$idx]['cost_price']);
            }
        }

        // Check duplicate/similar product names in the same category (similar_text >= 85%)
        $warnings = [];
        $normTargetAr = EntityResolver::normalizeForSearch((string) $validated['name_ar']);
        $normTargetEn = mb_strtolower(trim((string) $validated['name_en']));

        $sameCategoryProducts = Product::query()
            ->where('category_id', (int) $validated['category_id'])
            ->limit(200)
            ->get(['id', 'code', 'name_ar', 'name_en']);

        foreach ($sameCategoryProducts as $other) {
            similar_text($normTargetAr, EntityResolver::normalizeForSearch((string) $other->name_ar), $pctAr);
            similar_text($normTargetEn, mb_strtolower(trim((string) $other->name_en)), $pctEn);
            if ($pctAr >= 85.0 || $pctEn >= 85.0) {
                $warnings[] = "فيه منتج شبهه: {$other->name_ar} ({$other->code})";
            }
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $validated,
            risk: $this->risk(),
            title: "إنشاء مسودة منتج: {$validated['name_ar']} (".count($matrixRows).' متغير)',
            before: null,
            after: [
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'category_id' => $validated['category_id'],
                'sales_channel' => $validated['sales_channel'],
                'status' => 'draft',
                'variants_count' => count($matrixRows),
            ],
            warnings: $warnings,
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
        $data = $action->args;
        $data['status'] = 'draft';
        if (! CostAccess::canView($actor) && isset($data['variants']) && is_array($data['variants'])) {
            foreach ($data['variants'] as $idx => $v) {
                unset($data['variants'][$idx]['cost_price']);
            }
        }

        $product = $this->products->create($data, $actor);

        StaffAdministration::audit(
            $actor,
            'product.created',
            'product',
            $product->id,
            ['name_ar', 'name_en', 'category_id', 'sales_channel', 'status'],
            [],
            [
                'code' => $product->code,
                'name_ar' => $product->name_ar,
                'name_en' => $product->name_en,
                'category_id' => $product->category_id,
                'status' => $product->status->value,
                'count' => $product->variants->count(),
            ],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتعمل المنتج كمسودة بالكود {$product->code}.", [
            'product_id' => $product->id,
            'code' => $product->code,
            'status' => 'draft',
            'variants_count' => $product->variants->count(),
            'deep_link' => '#/products/'.$product->id,
            'next_steps' => 'ارفع صور ← افحص الجاهزية ← انشر',
        ]);
    }
}
