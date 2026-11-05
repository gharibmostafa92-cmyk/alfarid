<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryCoverage;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\DiscreteQuantity;

final class WhatIfTransferCoverageTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'what_if_transfer_coverage';
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
        return ['inventory.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يحسب تغطية الفرع قبل وبعد نقل افتراضي — من غير ما يكتب حاجة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['quantity', 'from_branch_id'],
                'properties' => (object) [
                    'variant_id' => ['type' => 'INTEGER'],
                    'sku' => ['type' => 'STRING'],
                    'barcode' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'quantity' => ['type' => 'INTEGER'],
                    'from_branch_id' => ['type' => 'INTEGER'],
                    'to_branch_id' => ['type' => 'INTEGER'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $resolved = InventoryArgs::resolveVariant($actor, $this->resolver, $args);
        if ($resolved instanceof ToolOutcome) {
            return $resolved;
        }
        try {
            DiscreteQuantity::assert($args['quantity'] ?? null);
        } catch (BusinessException $e) {
            return ToolOutcome::denied($e->getMessage(), auditable: false);
        }
        $variant = $resolved['variant'];
        $qty = (float) $args['quantity'];
        $from = (int) ($args['from_branch_id'] ?? 0);
        $to = isset($args['to_branch_id']) ? (int) $args['to_branch_id'] : null;
        if ($from < 1) {
            return ToolOutcome::denied('حدد فرع الإرسال.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && $from !== $restricted) {
            return ToolOutcome::denied('الفرع خارج نطاقك.', auditable: false);
        }

        $donorBefore = InventoryCoverage::coverageDays($variant->id, $from);
        $donorAvail = InventoryCoverage::available($variant->id, $from);
        $donorDaily = InventoryCoverage::avgDailySale($variant->id, $from);
        $donorAfter = $donorDaily > 0 ? round(max(0.0, $donorAvail - $qty) / $donorDaily, 1) : null;

        $data = [
            'variant' => ['id' => $variant->id, 'sku' => $variant->sku],
            'quantity' => $qty,
            'donor_branch_id' => $from,
            'donor_available' => $donorAvail,
            'donor_coverage_before' => $donorBefore,
            'donor_coverage_after' => $donorAfter,
            'verdict' => $qty > $donorAvail
                ? 'الكمية أكبر من المتاح في فرع الإرسال.'
                : ($donorAfter !== null && $donorAfter < 7 ? 'النقل ده هيخلّي فرع الإرسال مهدد بالنفاد.' : 'النقل آمن على فرع الإرسال.'),
        ];
        if ($to !== null) {
            $destBefore = InventoryCoverage::coverageDays($variant->id, $to);
            $data['dest_branch_id'] = $to;
            $data['dest_coverage_before'] = $destBefore;
        }

        return $this->presenter->present($actor, $data);
    }
}
