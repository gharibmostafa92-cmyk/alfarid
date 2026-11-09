<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Finance\ReportService;

final class DeadStockTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private ReportService $reports,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'dead_stock';
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
            'description' => 'الأصناف الراكدة بلا حركة (افتراضي 90 يوم) مع قيمة الأموال المحبوسة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'dead_days' => ['type' => 'INTEGER'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'branch_query' => ['type' => 'STRING'],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = InventoryArgs::normalize($args);
        $scope = InventoryArgs::scopeBranch($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        $deadDays = min(3650, max(1, (int) ($args['dead_days'] ?? 90)));
        $report = $this->reports->inventoryReport($deadDays, $scope['branch']?->id);

        $table = array_map(fn ($r) => [
            $r['sku'], $r['product_name'] ?? '—', (string) $r['on_hand'],
        ], array_slice($report['dead_stock'], 0, 20));

        return $this->presenter->present($actor, [
            'dead_stock' => $report['dead_stock'],
            'dead_stock_days' => $report['dead_stock_days'],
            'count' => count($report['dead_stock']),
        ], ['type' => 'table', 'columns' => ['SKU', 'الصنف', 'الرصيد'], 'rows' => $table]);
    }
}
