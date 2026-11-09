<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\InventoryReconcileRun;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Inventory\InventoryArgs;
use App\Services\Ai\Inventory\InventoryPresenter;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;

final class InventoryHealthTool implements AgentTool
{
    public function __construct(
        private BranchAccess $branches,
        private InventoryPresenter $presenter,
    ) {}

    public function name(): string
    {
        return 'inventory_health';
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
            'description' => 'آخر نتيجة مطابقة للأرصدة مع الدفتر (من التشغيل المجدول).',
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

        $run = InventoryReconcileRun::query()
            ->when($scope['branch'] !== null, fn ($q) => $q->where('branch_id', $scope['branch']->id), fn ($q) => $q->whereNull('branch_id'))
            ->orderByDesc('id')->first();

        if (! $run) {
            return $this->presenter->present($actor, [
                'has_run' => false,
            ], null, 'مفيش مطابقة مسجلة لسه. شغّل inventory:reconcile من المجدول.');
        }

        $samples = array_slice($run->samples ?? [], 0, 5);

        return $this->presenter->present($actor, [
            'has_run' => true,
            'checked' => (int) $run->checked,
            'problem_count' => (int) $run->problem_count,
            'samples' => $samples,
            'ran_at' => $run->created_at?->toIso8601String(),
        ]);
    }
}
