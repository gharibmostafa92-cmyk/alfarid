<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\Occasion;
use App\Enums\SalesChannel;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\CatalogUndoGuard;
use App\Services\Ai\Catalog\CatalogWarnings;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Contracts\UndoableTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\ProductPublishabilityChecker;
use App\Services\Catalog\ProductService;
use App\Services\StaffAdministration;
use Illuminate\Support\Arr;

final class UpdateProductTool implements LocksTargets, UndoableTool, WriteTool
{
    private const ALLOWED_FIELDS = [
        'category_id',
        'name_ar',
        'name_en',
        'slug',
        'description_ar',
        'description_en',
        'material_ar',
        'material_en',
        'length_cm',
        'width_cm',
        'height_cm',
        'weight_kg',
        'warranty_months',
        'warranty_details',
        'country_of_origin',
        'sales_channel',
        'is_featured',
        'occasions',
        'badge_en',
        'badge_ar',
    ];

    public function __construct(
        private ProductService $products,
        private ProductPublishabilityChecker $checker,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'update_product';
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
            'description' => 'تعديل بيانات منتج (الاسم، الوصف، الخامة، القسم، الأبعاد، قناة البيع، المناسبات، الشارة). لتغيير الحالة استخدم set_product_status.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'category_id' => ['type' => 'INTEGER'],
                    'name_ar' => ['type' => 'STRING'],
                    'name_en' => ['type' => 'STRING'],
                    'slug' => ['type' => 'STRING'],
                    'description_ar' => ['type' => 'STRING'],
                    'description_en' => ['type' => 'STRING'],
                    'material_ar' => ['type' => 'STRING'],
                    'material_en' => ['type' => 'STRING'],
                    'length_cm' => ['type' => 'NUMBER'],
                    'width_cm' => ['type' => 'NUMBER'],
                    'height_cm' => ['type' => 'NUMBER'],
                    'weight_kg' => ['type' => 'NUMBER'],
                    'warranty_months' => ['type' => 'INTEGER'],
                    'warranty_details' => ['type' => 'STRING'],
                    'country_of_origin' => ['type' => 'STRING'],
                    'sales_channel' => ['type' => 'STRING', 'enum' => array_column(SalesChannel::cases(), 'value')],
                    'is_featured' => ['type' => 'BOOLEAN'],
                    'occasions' => [
                        'type' => 'ARRAY',
                        'items' => ['type' => 'STRING', 'enum' => array_column(Occasion::cases(), 'value')],
                    ],
                    'badge_ar' => ['type' => 'STRING'],
                    'badge_en' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        if (isset($args['dimensions']) && is_array($args['dimensions'])) {
            foreach (['length_cm', 'width_cm', 'height_cm', 'weight_kg'] as $dimKey) {
                if (array_key_exists($dimKey, $args['dimensions']) && ! array_key_exists($dimKey, $args)) {
                    $args[$dimKey] = $args['dimensions'][$dimKey];
                }
            }
        }

        $product = Product::with(['category', 'variants', 'media'])->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $input = Arr::only($args, self::ALLOWED_FIELDS);
        $rules = Arr::only(UpdateProductRequest::baseRules($product->id), array_merge(self::ALLOWED_FIELDS, ['occasions.*']));
        $data = CatalogArgs::validate($input, $rules);

        if ($data === []) {
            return ToolOutcome::denied('مفيش حقول مطلوبة للتعديل.', auditable: false);
        }

        $warnings = CatalogWarnings::forProductChange($product, $data, $this->checker);

        $before = [];
        $after = [];
        $inverse = [];
        foreach (array_keys($data) as $field) {
            $oldVal = $product->getAttribute($field);
            if ($oldVal instanceof \BackedEnum) {
                $oldVal = $oldVal->value;
            }
            $before[$field] = $oldVal;
            $after[$field] = $data[$field];
            $inverse[$field] = $oldVal;
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data + ['product_id' => $product->id],
            risk: $this->risk(),
            title: "تعديل المنتج {$product->code}: {$product->name_ar}",
            before: $before,
            after: $after,
            warnings: $warnings,
            expectedVersions: ['product:'.$product->id => (int) $product->lock_version],
            inverse: $inverse,
        );
    }

    public function lockTargets(AiPendingAction $action): void
    {
        CatalogLock::tree(shared: true);
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $fields = Arr::except($action->args, ['product_id']);
        $before = [];
        foreach (array_keys($fields) as $f) {
            $v = $product->getAttribute($f);
            $before[$f] = $v instanceof \BackedEnum ? $v->value : $v;
        }

        $data = $fields + [
            'expected_version' => $action->expected_versions['product:'.$product->id] ?? null,
        ];
        $updated = $this->products->update($product, $data, $actor);

        $after = [];
        foreach (array_keys($fields) as $f) {
            $v = $updated->getAttribute($f);
            $after[$f] = $v instanceof \BackedEnum ? $v->value : $v;
        }

        StaffAdministration::audit(
            $actor,
            'product.updated',
            'product',
            $updated->id,
            array_keys($fields),
            $before,
            $after,
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$updated->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتعدل المنتج {$updated->code}.", [
            'product_id' => $updated->id,
            'code' => $updated->code,
            'deep_link' => '#/products/'.$updated->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return is_array($action->inverse) && $action->inverse !== [];
    }

    public function targetState(AiPendingAction $action): string
    {
        $p = Product::findOrFail((int) $action->args['product_id']);
        $keys = is_array($action->inverse) ? array_keys($action->inverse) : [];
        $attrs = [];
        foreach ($keys as $k) {
            $v = $p->getAttribute($k);
            $attrs[$k] = $v instanceof \BackedEnum ? $v->value : $v;
        }

        return hash('sha256', json_encode([$p->lock_version, $attrs], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        CatalogUndoGuard::assertUnchanged($this, $action);
        $updated = $this->products->update($product, (array) $action->inverse + ['expected_version' => (int) $product->lock_version], $actor);

        return ToolOutcome::result(true, "تم التراجع عن تعديل المنتج {$updated->code}.", [
            'product_id' => $updated->id,
            'code' => $updated->code,
            'deep_link' => '#/products/'.$updated->id,
        ]);
    }
}
