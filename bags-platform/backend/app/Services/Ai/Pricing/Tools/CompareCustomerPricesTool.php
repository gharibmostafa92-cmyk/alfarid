<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PricingService;

final class CompareCustomerPricesTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PricingService $pricing,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'compare_customer_prices';
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
        return ['price_lists.view', 'customers.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قارن سعر صنف واحد بين حتى 5 عملاء أو بين أنواع العملاء (قطاعي/جملة/موزع/مجموعة). الأسعار كلها من محرك التسعير.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'customer_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    'customer_types' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => ['retail', 'wholesale', 'distributor', 'group']]],
                    'customer_group_id' => ['type' => 'INTEGER'],
                    'qty' => ['type' => 'NUMBER'],
                    'branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        if (! $actor->can('customers.view')) {
            return ToolOutcome::denied('معندكش صلاحية عرض العملاء.');
        }
        $args = PricingArgs::normalize($args);
        $resolved = PricingArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $variant = $resolved['variant'];

        $qty = isset($args['qty']) ? (float) $args['qty'] : 1.0;
        if (! is_finite($qty) || $qty <= 0) {
            return ToolOutcome::denied('الكمية لازم تكون رقم موجب.', auditable: false);
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($args['customer_ids'] ?? []))));
        $types = array_values(array_unique((array) ($args['customer_types'] ?? [])));
        if ($ids === [] && $types === []) {
            return ToolOutcome::denied('حدد customer_ids أو customer_types للمقارنة.', auditable: false);
        }
        if (count($ids) > 5) {
            return ToolOutcome::denied('حد أقصى 5 عملاء في المقارنة الواحدة.', auditable: false);
        }
        if (count($ids) + count($types) > 6) {
            return ToolOutcome::denied('حد أقصى 6 أعمدة في المقارنة الواحدة.', auditable: false);
        }

        /** @var list<array{label:string,customer:?Customer}> $identities */
        $identities = [];
        foreach ($ids as $id) {
            $customer = Customer::query()->find($id);
            if (! $customer) {
                return ToolOutcome::denied("العميل رقم {$id} مش موجود.", auditable: false);
            }
            $identities[] = ['label' => $customer->name, 'customer' => $customer];
        }
        foreach ($types as $type) {
            if ($type === 'retail') {
                $identities[] = ['label' => 'قطاعي (زائر)', 'customer' => null];
                continue;
            }
            if ($type === 'group') {
                if (empty($args['customer_group_id'])) {
                    return ToolOutcome::denied('نوع group محتاج customer_group_id.', auditable: false);
                }
                $member = Customer::query()->where('customer_group_id', (int) $args['customer_group_id'])
                    ->where('status', CustomerStatus::Approved)->orderBy('id')->first();
                if (! $member) {
                    $identities[] = ['label' => 'مجموعة (لا يوجد عضو معتمد)', 'customer' => '__none__'];
                    continue;
                }
                $identities[] = ['label' => 'مجموعة (عضو: '.$member->name.')', 'customer' => $member];
                continue;
            }
            $customerType = $type === 'wholesale' ? CustomerType::Wholesale : CustomerType::Distributor;
            $member = Customer::query()->where('type', $customerType)
                ->where('status', CustomerStatus::Approved)->orderBy('id')->first();
            if (! $member) {
                $identities[] = ['label' => ($type === 'wholesale' ? 'جملة' : 'موزع').' (لا يوجد عميل معتمد)', 'customer' => '__none__'];
                continue;
            }
            $identities[] = ['label' => ($type === 'wholesale' ? 'جملة' : 'موزع').' (مثال: '.$member->name.')', 'customer' => $member];
        }

        $rows = [];
        $data = [];
        foreach ($identities as $identity) {
            if ($identity['customer'] === '__none__') {
                $rows[] = [$identity['label'], '—', '—', 'لا يوجد عميل معتمد'];
                $data[] = ['label' => $identity['label'], 'unit_price' => null, 'note' => 'لا يوجد عميل معتمد'];
                continue;
            }
            try {
                $result = $this->pricing->resolve($variant, $identity['customer'], $qty, $scope['branchId']);
            } catch (BusinessException $e) {
                $rows[] = [$identity['label'], '—', '—', $e->getMessage()];
                $data[] = ['label' => $identity['label'], 'unit_price' => null, 'note' => $e->getMessage()];
                continue;
            }
            $rows[] = [$identity['label'], (string) $result['unit_price'], (string) $result['base_price'], $result['source']];
            $data[] = ['label' => $identity['label'], 'customer_id' => $identity['customer']?->id] + $result;
        }

        return $this->presenter->present($actor, [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'qty' => $qty,
            'prices' => $data,
        ], $this->presenter->table(['العميل/النوع', 'سعر الوحدة', 'السعر الأساسي', 'المصدر'], $rows));
    }
}
