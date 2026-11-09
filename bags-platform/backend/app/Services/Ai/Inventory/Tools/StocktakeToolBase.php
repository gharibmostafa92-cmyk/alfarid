<?php

declare(strict_types=1);

namespace App\Services\Ai\Inventory\Tools;

use App\Models\Stocktake;
use App\Models\User;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Inventory\StocktakeService;

abstract class StocktakeToolBase implements LocksTargets
{
    public function __construct(
        protected EntityResolver $resolver,
        protected BranchAccess $branches,
        protected StocktakeService $stocktakes,
        protected ProposalBuilder $proposals,
    ) {}

    /**
     * @param  array<string,mixed>  $args
     * @return array{stocktake: Stocktake}|ToolOutcome
     */
    protected function resolveStocktake(User $actor, array $args): array|ToolOutcome
    {
        $stocktake = null;
        if (! empty($args['stocktake_id'])) {
            $stocktake = Stocktake::find((int) $args['stocktake_id']);
        } elseif (! empty($args['stocktake_number'])) {
            $stocktake = Stocktake::whereRaw('LOWER(stocktake_number) = LOWER(?)', [trim((string) $args['stocktake_number'])])->first();
        } else {
            $options = $this->resolver->stocktakes($actor, (string) ($args['query'] ?? ''));
            if (count($options) !== 1) {
                return ToolOutcome::denied('حدد الجرد برقمه. استخدم find_entities ثم ask_user_to_choose.', auditable: false);
            }
            $stocktake = Stocktake::find($options[0]['id']);
        }
        if (! $stocktake) {
            return ToolOutcome::denied('الجرد مش موجود.', auditable: false);
        }
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }
        if ($restricted !== null && (int) $stocktake->branch_id !== $restricted) {
            return ToolOutcome::denied('الجرد خارج نطاق فرعك.', auditable: false);
        }

        return ['stocktake' => $stocktake->loadMissing(['items.variant', 'branch', 'location'])];
    }

    public function lockTargets(\App\Models\AiPendingAction $action): void
    {
        Stocktake::query()->whereKey((int) ($action->args['stocktake_id'] ?? 0))->lockForUpdate()->first();
    }
}
