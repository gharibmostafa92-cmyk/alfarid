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

final class ExplainPriceTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PricingService $pricing,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'explain_price';
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
            'description' => 'اشرح ليه سعر صنف معين طالع كده لعميل معين (شجرة التسعير طبقة طبقة: العميل الخاص ← المجموعة ← القناة ← الأساسي). استخدمها لأي سؤال "ليه السعر كده". ماتحسبش أسعار بنفسك أبدًا — السعر دائمًا من محرك التسعير.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_query' => ['type' => 'STRING'],
                    'qty' => ['type' => 'NUMBER'],
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

        $customerResolved = PricingArgs::resolveCustomer($actor, $this->resolver, $args);
        if ($customerResolved instanceof ToolOutcome) {
            return $customerResolved;
        }
        $customer = $customerResolved['customer'];

        $qty = isset($args['qty']) ? (float) $args['qty'] : 1.0;
        if (! is_finite($qty) || $qty <= 0) {
            return ToolOutcome::denied('الكمية لازم تكون رقم موجب.', auditable: false);
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }

        try {
            $explanation = $this->pricing->trace($variant, $customer, $qty, $scope['branchId']);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }

        $steps = array_map(fn (array $layer) => [
            'layer' => $layer['layer'],
            'status' => $layer['status'],
            'icon' => match ($layer['status']) {
                'won' => '✓',
                'no_match' => '✗',
                default => '⊘',
            },
            'reason' => $layer['reason'],
            'price_list_id' => $layer['price_list_id'] ?? null,
            'price' => $layer['price'] ?? null,
            'min_qty' => $layer['min_qty'] ?? null,
        ], $explanation['layers']);

        $final = $explanation['final'];
        $block = [
            'type' => 'price_waterfall',
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku, 'name_ar' => $this->presenter->untrusted($variant->product?->name_ar ?? '', 150)],
            'customer' => $customer ? ['id' => $customer->id, 'name' => $this->presenter->untrusted($customer->name, 120), 'type' => $customer->type->value, 'status' => $customer->status->value] : null,
            'qty' => $qty,
            'steps' => $steps,
            'notes' => $explanation['notes'],
            'final_price' => $final['unit_price'],
            'source' => $final['source'],
            'deep_link' => $final['price_list_id'] !== null ? '#/pricing?list='.$final['price_list_id'] : null,
        ];

        return $this->presenter->present($actor, [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'customer_id' => $customer?->id,
            'qty' => $qty,
            'final' => $final,
            'layers' => $explanation['layers'],
            'notes' => $explanation['notes'],
        ], $block);
    }
}
