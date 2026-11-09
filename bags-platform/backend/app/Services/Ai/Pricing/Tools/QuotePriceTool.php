<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PricingService;

final class QuotePriceTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PricingService $pricing,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'quote_price';
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
            'description' => 'اعرض سلم أسعار صنف لكميات مختلفة (سعر الوحدة لكل كمية ونسبة التوفير). الأسعار كلها من محرك التسعير — ماتحسبش بنفسك.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'customer_id' => ['type' => 'INTEGER'],
                    'quantities' => ['type' => 'ARRAY', 'items' => ['type' => 'NUMBER']],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $resolved = PricingArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        $variant = $resolved['variant'];

        $customer = null;
        if (! empty($args['customer_id'])) {
            if (! $actor->can('customers.view')) {
                return ToolOutcome::denied('معندكش صلاحية عرض العملاء.');
            }
            $customerResolved = PricingArgs::resolveCustomer($actor, $this->resolver, $args);
            if ($customerResolved instanceof ToolOutcome) {
                return $customerResolved;
            }
            $customer = $customerResolved['customer'];
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        $quantities = [1.0];
        foreach ((array) ($args['quantities'] ?? []) as $q) {
            if (is_numeric($q) && is_finite((float) $q) && (float) $q > 0) {
                $quantities[] = (float) $q;
            }
        }
        $quantities = array_values(array_unique($quantities));
        sort($quantities);
        if (count($quantities) > 20) {
            return ToolOutcome::denied('حد أقصى 20 كمية في عرض السعر الواحد.', auditable: false);
        }

        try {
            $tiers = empty($args['quantities'])
                ? $this->pricing->tiersFor($variant, $customer, branchId: $scope['branchId'])
                : array_map(fn (float $qty) => [
                    'min_qty' => $qty,
                    'unit_price' => $this->pricing->resolve($variant, $customer, $qty, $scope['branchId'])['unit_price'],
                ], $quantities);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        $base = collect($tiers)->firstWhere('min_qty', 1.0)['unit_price'];
        $rows = [];
        foreach ($tiers as $tier) {
            $saving = $base > 0 ? round((($base - $tier['unit_price']) / $base) * 100, 2) : 0.0;
            $rows[] = [
                'min_qty' => $tier['min_qty'],
                'unit_price' => $tier['unit_price'],
                'saving_pct' => max(0.0, $saving),
            ];
        }

        $table = array_map(fn ($r) => [(string) $r['min_qty'], (string) $r['unit_price'], $r['saving_pct'].'%'], $rows);

        return $this->presenter->present($actor, [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'customer_id' => $customer?->id,
            'tiers' => $rows,
        ], ['type' => 'tier_ladder'] + $this->presenter->table(['الكمية', 'سعر الوحدة', 'التوفير %'], $table));
    }
}
