<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\CatalogLock;
use App\Services\Catalog\ProductService;
use App\Services\StaffAdministration;
use Illuminate\Validation\ValidationException;

final class DeleteProductTool implements LocksTargets, WriteTool
{
    public function __construct(
        private ProductService $products,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'delete_product';
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
        return ['products.delete'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'حذف منتج ناعمًا (soft delete) مع متغيراته بشرط عدم وجود رصيد مخزون.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
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

        if ($product->status === ProductStatus::Published && ! $actor->can('products.publish')) {
            return ToolOutcome::denied('حذف منتج منشور يتطلب صلاحية النشر (products.publish).', auditable: true);
        }

        $variantIds = $product->variants->pluck('id')->all();
        if ($variantIds !== [] && StockLevel::query()->whereIn('product_variant_id', $variantIds)->hasNonZeroBalance()->exists()) {
            throw ValidationException::withMessages([
                'product_id' => ['لا يمكن حذف منتج له متغيرات برصيد مخزون؛ صفِّ الأرصدة أولًا أو أرشفه بـ set_product_status.'],
            ]);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['product_id' => $product->id],
            risk: $this->risk(),
            title: "حذف المنتج {$product->code}: {$product->name_ar}",
            before: [
                'id' => $product->id,
                'code' => $product->code,
                'name_ar' => $product->name_ar,
                'status' => $product->status->value,
                'variants_count' => count($variantIds),
            ],
            after: null,
            warnings: ['لو المنتج عليه أوردرات، الأرشفة أأمن'],
            expectedVersions: ['product:'.$product->id => (int) $product->lock_version],
            typedConfirmation: $product->code,
            typedConfirmationHint: 'اكتب كود المنتج للتأكيد',
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

        $code = $product->code;
        $id = $product->id;
        $before = ['code' => $product->code, 'name_ar' => $product->name_ar, 'status' => $product->status->value];

        $this->products->delete($product, $actor);

        StaffAdministration::audit(
            $actor,
            'product.deleted',
            'product',
            $id,
            ['id', 'code', 'status'],
            $before,
            [],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتحذف المنتج {$code}.", [
            'product_id' => $id,
            'code' => $code,
            'deep_link' => '#/products',
        ]);
    }
}
