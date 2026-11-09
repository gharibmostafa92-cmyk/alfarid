<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Jobs\RefreshCatalogRetailPrices;
use App\Models\AiPendingAction;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Auth\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RefreshCatalogPricesTool implements WriteTool
{
    public function __construct(
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'refresh_catalog_prices';
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
        return ['price_lists.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تحديث لقطة أسعار الكتالوج للمنتجات القديمة (dispatch واحد). استخدمها بعد تعديلات أسعار كبيرة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'product_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $limit = min(200, max(1, (int) ($args['limit'] ?? 100)));

        if (! empty($args['product_ids'])) {
            $ids = array_values(array_unique(array_map('intval', (array) $args['product_ids'])));
            $found = Product::query()->whereIn('id', $ids)->pluck('id')->map('intval')->all();
            $missing = array_diff($ids, $found);
            if ($missing !== []) {
                throw ValidationException::withMessages(['product_ids' => 'منتجات مش موجودة: '.implode('، ', $missing).'.']);
            }
            $ids = array_slice($found, 0, $limit);
        } else {
            $ids = Product::query()
                ->whereNotNull('products.catalog_price_refreshed_at')
                ->whereExists(fn ($q) => $q->select(DB::raw('1'))->from('price_list_items')
                    ->join('product_variants as pv', 'pv.id', '=', 'price_list_items.product_variant_id')
                    ->whereColumn('pv.product_id', 'products.id')
                    ->whereColumn('price_list_items.updated_at', '>', 'products.catalog_price_refreshed_at'))
                ->orderBy('products.id')
                ->limit($limit)
                ->pluck('products.id')
                ->map('intval')
                ->all();
        }

        if ($ids === []) {
            throw ValidationException::withMessages(['product_ids' => 'مفيش منتجات لقطتها قديمة ومحتاجة تحديث.']);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            ['product_ids' => array_values($ids)],
            risk: $this->risk(),
            title: 'تحديث لقطة أسعار الكتالوج ('.count($ids).' منتج)',
            before: ['stale_products' => count($ids)],
            after: ['queued_products' => count($ids)],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $ids = array_values(array_map('intval', (array) ($action->args['product_ids'] ?? [])));
        RefreshCatalogRetailPrices::dispatch($ids);

        StaffAdministration::audit(
            $actor,
            'catalog.prices_refresh_queued',
            'product',
            null,
            ['count'],
            [],
            ['count' => count($ids)],
            ['channel' => 'ai', 'tool' => $this->name(), 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم إرسال تحديث اللقطة لـ '.count($ids).' منتج.', [
            'queued_products' => count($ids),
        ]);
    }
}
