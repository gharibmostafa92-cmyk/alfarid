<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Services\Shared\CostAccess;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Finance\ReportService;

final class InventoryValuationTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private ReportService $reports,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'inventory_valuation';
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
        return ['inventory.view_cost'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'قيمة المخزون حسب الفرع بسعر التكلفة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
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
        $report = $this->reports->inventoryReport(90, $scope['branch']?->id);

        $showCost = \App\Services\Shared\CostAccess::canView($actor);
        $table = array_map(fn ($r) => array_merge([
            $r['branch_name'], (string) $r['variants_count'], (string) $r['total_qty'],
        ], $showCost ? [(string) $r['total_value']] : []), $report['valuation']);
        $columns = ['الفرع', 'الأصناف', 'الكمية'];
        if ($showCost) {
            $columns[] = 'القيمة';
        }

        return $this->presenter->present($actor, [
            'valuation' => $report['valuation'],
            'total_value' => $report['total_value'],
        ], ['type' => 'table', 'columns' => $columns, 'rows' => $table]);
    }
}
