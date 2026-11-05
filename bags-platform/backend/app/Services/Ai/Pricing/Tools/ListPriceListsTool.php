<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\PriceListType;
use App\Models\PriceList;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use Carbon\Carbon;

final class ListPriceListsTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'list_price_lists';
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
            'description' => 'اعرض قوائم الأسعار مع فلاتر (النوع، العميل، المجموعة، الفرع، النشطة، اللي بتخلص خلال أيام). المستخدم المقيد بفرع بيشوف قوائم فرعه بس.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'type' => ['type' => 'STRING', 'enum' => array_column(PriceListType::cases(), 'value')],
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_group_id' => ['type' => 'INTEGER'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'active' => ['type' => 'BOOLEAN'],
                    'expiring_within_days' => ['type' => 'INTEGER'],
                    'limit' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $now = Carbon::now();

        $lists = PriceList::query()
            ->with(['branch:id,name', 'customer:id,name', 'customerGroup:id,name'])
            ->withCount('items')
            ->when($scope['branchId'] !== null, fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when(! empty($args['type']), fn ($q) => $q->where('type', $args['type']))
            ->when(! empty($args['customer_id']), fn ($q) => $q->where('customer_id', (int) $args['customer_id']))
            ->when(! empty($args['customer_group_id']), fn ($q) => $q->where('customer_group_id', (int) $args['customer_group_id']))
            ->when(array_key_exists('active', $args), fn ($q) => $q->where('is_active', (bool) $args['active']))
            ->when(! empty($args['expiring_within_days']), fn ($q) => $q
                ->whereNotNull('ends_at')
                ->where('ends_at', '>=', $now)
                ->where('ends_at', '<=', $now->copy()->addDays(max(1, (int) $args['expiring_within_days']))))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $data = [];
        foreach ($lists as $list) {
            $type = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
            $owner = match ($type) {
                PriceListType::CustomerSpecific->value => 'عميل: '.($list->customer?->name ?? $list->customer_id),
                PriceListType::CustomerGroup->value => 'مجموعة: '.($list->customerGroup?->name ?? $list->customer_group_id),
                default => '—',
            };
            $rows[] = [
                (string) $list->id,
                $list->name,
                $type,
                $owner,
                $list->branch?->name ?? 'عام',
                (string) $list->priority,
                (string) $list->items_count,
                $list->is_active ? 'نشطة' : 'متوقفة',
                $list->ends_at?->format('Y-m-d') ?? '—',
            ];
            $data[] = [
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
            ];
        }

        return $this->presenter->present($actor, [
            'price_lists' => $data,
            'count' => count($data),
        ], $this->presenter->table(
            ['الرقم', 'الاسم', 'النوع', 'المالك', 'الفرع', 'الأولوية', 'البنود', 'الحالة', 'ينتهي في'],
            $rows
        ));
    }
}
