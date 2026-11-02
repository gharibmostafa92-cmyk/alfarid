<?php

declare(strict_types=1);

namespace App\Services\Ai\Catalog\Tools;

use App\Enums\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Catalog\CatalogArgs;
use App\Services\Ai\Catalog\CatalogPresenter;
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

final class SetProductStatusTool implements LocksTargets, UndoableTool, WriteTool
{
    public function __construct(
        private ProductService $products,
        private ProductPublishabilityChecker $checker,
        private ProposalBuilder $proposals,
        private CatalogPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'set_product_status';
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
            'description' => 'تغيير حالة منتج (draft / published / archived). لو التحويل لـ published وفيه نواقص، هيرجع قائمة النواقص بدل اقتراح النشر.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['product_id', 'status'],
                'properties' => (object) [
                    'product_id' => ['type' => 'INTEGER'],
                    'status' => ['type' => 'STRING', 'enum' => array_column(ProductStatus::cases(), 'value')],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = CatalogArgs::normalizeDigitsInArray($args);
        $product = Product::with(['category', 'variants', 'media'])->find((int) ($args['product_id'] ?? 0));
        if (! $product) {
            return ToolOutcome::denied('المنتج مش موجود.', auditable: false);
        }

        $targetStatus = ProductStatus::tryFrom((string) ($args['status'] ?? ''));
        if (! $targetStatus) {
            return ToolOutcome::denied('الحالة المطلوبة غير صحيحة.', auditable: false);
        }

        $currentStatus = $product->status;
        $publishInvolved = $currentStatus === ProductStatus::Published || $targetStatus === ProductStatus::Published;

        if ($publishInvolved && ! $actor->can('products.publish')) {
            return ToolOutcome::denied('ليس لديك صلاحية نشر أو إلغاء نشر المنتجات (products.publish).', auditable: true);
        }

        if ($targetStatus === ProductStatus::Published) {
            $checks = $this->checker->evaluate($product);
            $failed = array_values(array_filter($checks, fn ($c) => ! $c['ok']));
            if ($failed !== []) {
                $nextActions = CheckPublishabilityTool::buildNextActions($product, $checks);
                $uiChecks = array_map(fn ($c) => [
                    'key' => $c['key'],
                    'ok' => $c['ok'],
                    'label' => $c['label'],
                    'hint' => $c['hint'],
                    'severity' => 'blocker',
                    'message' => $c['label'].($c['hint'] ? ' — '.$c['hint'] : ''),
                ], $checks);

                return $this->presenter->present(
                    $actor,
                    [
                        'can_publish' => false,
                        'product_id' => $product->id,
                        'code' => $product->code,
                        'status' => $currentStatus->value,
                        'checks' => $checks,
                        'next_actions' => $nextActions,
                        'deep_link' => '#/products/'.$product->id,
                    ],
                    [
                        'type' => 'checklist',
                        'title' => "لا يمكن نشر المنتج {$product->code} قبل استيفاء الشروط",
                        'verdict' => 'blocked',
                        'checks' => $uiChecks,
                        'next_actions' => $nextActions,
                    ],
                    'المنتج لسه ناقصه شروط قبل النشر.',
                );
            }
        }

        $risk = $publishInvolved ? 'high' : 'medium';

        return $this->proposals->make(
            $actor,
            $this->name(),
            [
                'product_id' => $product->id,
                'status' => $targetStatus->value,
            ],
            risk: $risk,
            title: "تغيير حالة المنتج {$product->code} إلى {$targetStatus->value}",
            before: ['status' => $currentStatus->value],
            after: ['status' => $targetStatus->value],
            expectedVersions: [
                'product:'.$product->id => (int) $product->lock_version,
                'target_version:'.$product->id => (int) $product->lock_version + 1,
            ],
            inverse: [
                'status' => $currentStatus->value,
                'from_status' => $currentStatus->value,
                'to_status' => $targetStatus->value,
            ],
            typedConfirmation: $risk === 'high' ? $product->code : null,
            typedConfirmationHint: $risk === 'high' ? 'اكتب كود المنتج للتأكيد' : null,
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

        $oldStatus = $product->status->value;
        $targetStatus = ProductStatus::from((string) $action->args['status']);
        $this->products->changeStatus($product, $targetStatus, $actor);

        StaffAdministration::audit(
            $actor,
            'product.status_changed',
            'product',
            $product->id,
            ['status'],
            ['status' => $oldStatus],
            ['status' => $product->status->value],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'product:'.$product->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, "اتغيرت حالة المنتج {$product->code} إلى {$product->status->value}.", [
            'product_id' => $product->id,
            'code' => $product->code,
            'status' => $product->status->value,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }

    public function canUndo(AiPendingAction $action): bool
    {
        if (! is_array($action->inverse) || empty($action->inverse['status'])) {
            return false;
        }
        $p = Product::find((int) ($action->args['product_id'] ?? 0));
        if (! $p) {
            return false;
        }
        $expectedAfter = $action->expected_versions['target_version:'.$p->id] ?? null;
        if ($expectedAfter !== null && (int) $p->lock_version !== (int) $expectedAfter) {
            return false;
        }

        return true;
    }

    public function targetState(AiPendingAction $action): string
    {
        $p = Product::findOrFail((int) $action->args['product_id']);

        return hash('sha256', json_encode([$p->status->value, $p->lock_version], JSON_THROW_ON_ERROR));
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        $product = Product::findOrFail((int) $action->args['product_id']);
        $expectedAfter = $action->expected_versions['target_version:'.$product->id] ?? null;
        if ($expectedAfter !== null && (int) $product->lock_version !== (int) $expectedAfter) {
            throw new BusinessException('المنتج اتعدل بعد تغيير حالته؛ ما ينفعش التراجع التلقائي.', [], 409);
        }

        $targetStatus = ProductStatus::from((string) $action->inverse['status']);
        $this->products->changeStatus($product, $targetStatus, $actor);

        return ToolOutcome::result(true, "تم التراجع ورجوع حالة المنتج {$product->code} إلى {$product->status->value}.", [
            'product_id' => $product->id,
            'code' => $product->code,
            'status' => $product->status->value,
            'deep_link' => '#/products/'.$product->id,
        ]);
    }
}
