<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\PriceListType;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\PriceListService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\Paginator;

final class GetPriceListTool implements AgentTool
{
    public function __construct(
        private PriceListService $lists,
        private PricingPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'get_price_list';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
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
            'description' => 'اعرض تفاصيل قائمة أسعار واحدة مع صفحة من بنودها (المتغير، الحد الأدنى للكمية، السعر).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'page' => ['type' => 'INTEGER'],
                    'per_page' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['price_list_id'])) {
            return ToolOutcome::denied('حدد price_list_id.', auditable: false);
        }

        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        $perPage = min(50, max(1, (int) ($args['per_page'] ?? 15)));
        $page = max(1, (int) ($args['page'] ?? 1));
        Paginator::currentPageResolver(fn () => $page);
        try {
            $items = $this->lists->paginateItems($list, ['per_page' => $perPage], $actor);
        } finally {
            Paginator::currentPageResolver(fn ($pageName = 'page') => (int) (request()->query($pageName, 1) ?? 1));
        }

        $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                $item->variant?->sku ?? ('#'.$item->product_variant_id),
                (string) $item->min_qty,
                (string) $item->price,
                $item->starts_at?->format('Y-m-d') ?? '—',
                $item->ends_at?->format('Y-m-d') ?? '—',
            ];
        }

        return $this->presenter->present($actor, [
            'price_list' => [
                'id' => $list->id,
                'name' => $this->presenter->untrusted($list->name, 150),
                'type' => $type,
                'customer_id' => $list->customer_id,
                'customer_group_id' => $list->customer_group_id,
                'branch_id' => $list->branch_id,
                'priority' => (int) $list->priority,
                'is_active' => (bool) $list->is_active,
                'starts_at' => $list->starts_at?->toIso8601String(),
                'ends_at' => $list->ends_at?->toIso8601String(),
                'items_count' => (int) $list->items_count,
                'deep_link' => '#/pricing?list='.$list->id,
            ],
            'items' => array_map(fn ($r) => ['sku' => $r[0], 'min_qty' => $r[1], 'price' => $r[2], 'starts_at' => $r[3], 'ends_at' => $r[4]], $rows),
            'page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ], $this->presenter->table(
            ['الصنف (SKU)', 'الحد الأدنى', 'السعر', 'من', 'إلى'],
            $rows
        ));
    }
}
