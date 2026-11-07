<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\ToolOutcome;

interface UndoableTool
{
    public function canUndo(AiPendingAction $action): bool;

    /**
     * Compute state hash post-execution to detect concurrent modifications prior to undo.
     */
    public function targetState(AiPendingAction $action): string;

    public function undo(User $actor, AiPendingAction $action): ToolOutcome;
}
